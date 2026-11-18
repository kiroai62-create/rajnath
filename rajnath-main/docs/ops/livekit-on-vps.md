# LiveKit (in-app audio/video calls) on your own VPS

LiveKit server is open source (Apache-2.0) and runs on an ordinary Linux VPS. It can share a box with the website, but a separate small VPS is better.

## Requirements
- A VPS with its **own public IPv4** and root access (Docker). Shared hosting or cPanel will not work. A VPS behind carrier-grade NAT will not work either.
- A subdomain such as `rtc.astrorajnathjha.com` with a trusted TLS certificate. Caddy obtains one automatically; self-signed certificates do not work.
- Firewall ports:
  - **443/TCP**: signalling (WebSocket) through the reverse proxy.
  - **7881/TCP**: media fallback when UDP is blocked.
  - **7882/UDP**: media, as a single multiplexed port. A range such as 50000–60000/UDP also works.
- For customers on office or hotel Wi-Fi that blocks UDP: enable LiveKit's built-in TURN on 443/5349 (TLS) using a second subdomain.
- Size:
  - 2 vCPU / 4 GB RAM is plenty for a few simultaneous one-to-one calls.
  - Bandwidth per call: about 0.1 Mbps for audio, 1–2 Mbps per person for video.

## What we need from you to connect it
1. A VPS that meets the requirements above, and a DNS record pointing to it.
2. Permission to open the ports above (in some providers' panels these are "security groups").

After that we add a `livekit` service to `deploy/docker-compose.yml` and the API key and secret to Admin settings. The website and app then get short-lived room tokens for each booking. Join is allowed from 15 minutes before the slot until 60 minutes after it ends, and only for that customer and that astrologer.

## Setup steps
The configuration is in the repository: `deploy/livekit.yaml`, the `livekit` service in `deploy/docker-compose.yml`, and a `rtc.` site in `deploy/Caddyfile`.

1. Create a DNS A record for `rtc.<your-domain>` pointing to the VPS IP.
2. Open the firewall ports: `ufw allow 7881/tcp && ufw allow 7882/udp` (also open them in your provider's panel if it has one).
3. In `.env.production`, set `LIVEKIT_API_KEY` and `LIVEKIT_API_SECRET`:
   - key: `openssl rand -hex 16`
   - secret: `openssl rand -base64 36`
4. Start the services: `docker compose --profile calls up -d`.
5. Go to Admin › Calling. Enter the URL `wss://rtc.<your-domain>`, the same key and the same secret. Press "Test LiveKit".
6. Choose a default provider: in-app, Zoom or Google Meet. Zoom and Google Meet need their own credentials, which you enter on the same page.


## Call reliability (Nov 2026)
- `deploy/livekit.yaml` listens on all addresses (`0.0.0.0`). Caddy reaches it through the Docker host gateway, which
  `127.0.0.1` refused. Port **7880 must stay closed** in the AWS security group / ufw; open only 7881/TCP and 7882/UDP.
- Rooms stay open 10 minutes after everyone left (`empty_timeout` and `departure_timeout`), so a dropped call rejoins
  the same room.
- The website and the app rejoin by themselves after a network drop or a server restart (about 3 minutes of retries).
  The app keeps the microphone working in the background with a "Consultation call in progress" notification.
- Opening the same call in two tabs or devices moves the call to the newest one; the older one says so and waits.
- Admin › Bookings › **call log** shows, per booking, who joined, every drop and its reason, rejoins and camera or
  microphone problems (website and app).
- Upgrade the server image when convenient: set `LIVEKIT_VERSION=v1.13.11` in `deploy/.env.production` (tested with
  this version), then run `deploy/deploy.sh`.
