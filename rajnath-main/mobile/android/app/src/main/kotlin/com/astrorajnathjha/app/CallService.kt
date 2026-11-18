package com.astrorajnathjha.app

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.os.Build
import android.os.IBinder

/**
 * Foreground service while a consultation call is live. Without it Android stops the microphone and camera of an
 * app in the background (and may close it), so the call dropped when the customer switched apps or locked the
 * phone. Shows an ongoing "Consultation call in progress" notification that opens the app.
 */
class CallService : Service() {
    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_STOP) {
            stopSelf()
            return START_NOT_STICKY
        }
        val video = intent?.getBooleanExtra(EXTRA_VIDEO, false) ?: false
        val title = intent?.getStringExtra(EXTRA_TITLE) ?: "Consultation call"
        val notification = buildNotification(title)
        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
                var type = ServiceInfo.FOREGROUND_SERVICE_TYPE_MICROPHONE
                if (video) type = type or ServiceInfo.FOREGROUND_SERVICE_TYPE_CAMERA
                startForeground(NOTIFICATION_ID, notification, type)
            } else {
                startForeground(NOTIFICATION_ID, notification)
            }
        } catch (e: Exception) {
            // Not allowed right now (for example permission missing): the call still works while the app is open.
            stopSelf()
        }
        return START_NOT_STICKY
    }

    override fun onTaskRemoved(rootIntent: Intent?) {
        // The app was swiped away: end the notification with it.
        stopSelf()
        super.onTaskRemoved(rootIntent)
    }

    private fun buildNotification(title: String): Notification {
        val manager = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            manager.createNotificationChannel(NotificationChannel(CHANNEL_ID, "Consultation calls", NotificationManager.IMPORTANCE_LOW))
        }
        val open = PendingIntent.getActivity(
            this, 0,
            Intent(this, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_REORDER_TO_FRONT),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        val builder = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) Notification.Builder(this, CHANNEL_ID) else @Suppress("DEPRECATION") Notification.Builder(this)
        return builder
            .setSmallIcon(R.mipmap.ic_launcher)
            .setContentTitle(title)
            .setContentText("Consultation call in progress. Tap to return to the call.")
            .setContentIntent(open)
            .setOngoing(true)
            .setCategory(Notification.CATEGORY_CALL)
            .build()
    }

    companion object {
        const val ACTION_STOP = "com.astrorajnathjha.app.CALL_STOP"
        const val EXTRA_VIDEO = "video"
        const val EXTRA_TITLE = "title"
        private const val CHANNEL_ID = "arj_call"
        private const val NOTIFICATION_ID = 4712

        fun start(context: Context, video: Boolean, title: String) {
            val i = Intent(context, CallService::class.java).putExtra(EXTRA_VIDEO, video).putExtra(EXTRA_TITLE, title)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) context.startForegroundService(i) else context.startService(i)
        }

        fun stop(context: Context) {
            context.stopService(Intent(context, CallService::class.java))
        }
    }
}
