<?php

namespace App\Http\Middleware;

use App\Domain\Translation\PageTranslator;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The app's counterpart of TranslatePage: puts the readable fields of GET /api/v1 answers (titles, summaries,
 * bodies, FAQ, labels) into the language the app asked for, from the website's translation memory.
 *
 * Only public answers (no sign-in) queue new text for AI translation. Answers for a signed-in customer use
 * existing translations only, so their own names, notes and messages are never sent to AI.
 */
class TranslateApiJson
{
    /** Fields whose text is shown to people. Codes, refs, slugs, URLs and numbers are never touched. */
    private const KEYS = [
        'title', 'summary', 'body', 'description', 'name', 'label', 'intro', 'excerpt', 'subtitle', 'heading', 'eyebrow',
        'text', 'caption', 'question', 'answer', 'q', 'a', 'includes', 'audience', 'process', 'benefits', 'exclusions',
        'disclaimer', 'family_name', 'variant_name', 'notice', 'meaning', 'note', 'tagline', 'cta', 'message', 'blurb',
    ];

    /** Answers that are already in the right language or are about the customer themself. */
    private const SKIP = ['api/v1/app-strings', 'api/v1/me', 'api/v1/me/*', 'api/v1/places', 'api/v1/notifications', 'api/v1/support', 'api/v1/support/*', 'api/v1/privacy'];

    public function __construct(private PageTranslator $translator) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $locale = app()->getLocale();
        if ($locale === 'en' || ! $request->isMethod('GET') || ! $response instanceof JsonResponse || $response->getStatusCode() >= 400 || $request->is(...self::SKIP)) {
            return $response;
        }
        try {
            $data = $response->getData(true);
            $texts = [];
            $this->collect($data, $texts, false);
            if (! $texts) {
                return $response;
            }
            $learn = $request->bearerToken() === null && $request->user('sanctum') === null;
            $found = $this->translator->lookup(array_keys($texts), $locale, $learn);
            if ($found) {
                $response->setData($this->apply($data, $found, false));
            }
        } catch (Throwable $e) {
            // A translation problem must never break the app: answer in English instead.
            Log::warning('API translation failed', ['locale' => $locale, 'path' => $request->path(), 'error' => $e->getMessage()]);
        }

        return $response;
    }

    /** @param array<string, true> $texts */
    private function collect(mixed $node, array &$texts, bool $wanted): void
    {
        if (is_array($node)) {
            foreach ($node as $k => $v) {
                $this->collect($v, $texts, is_string($k) ? in_array($k, self::KEYS, true) : $wanted);
            }
        } elseif ($wanted && is_string($node) && ($t = PageTranslator::normalise($node)) !== null && $t === $node) {
            $texts[$node] = true;
        }
    }

    /** @param array<string, string> $found */
    private function apply(mixed $node, array $found, bool $wanted): mixed
    {
        if (is_array($node)) {
            foreach ($node as $k => $v) {
                $node[$k] = $this->apply($v, $found, is_string($k) ? in_array($k, self::KEYS, true) : $wanted);
            }

            return $node;
        }

        return $wanted && is_string($node) && isset($found[$node]) ? $found[$node] : $node;
    }
}
