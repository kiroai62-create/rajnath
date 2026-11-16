<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Translation\PageTranslator;
use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The app's own screen text (buttons, titles, hints) in the chosen language. The English list is
 * resources/app/strings.json, made from the app source by mobile/tool/extract_strings.py; the app
 * never sends text of its own, so nothing a person typed reaches the AI. Translations come from the
 * same memory as the website (lang/{code}.json, then Admin › Languages); missing lines are queued
 * for AI translation and appear on a later request.
 */
class AppStringsController extends Controller
{
    public function __invoke(Request $request, PageTranslator $translator): JsonResponse
    {
        $locale = (string) $request->query('locale', app()->getLocale());
        abort_unless(in_array($locale, SetLocale::enabled(), true), 404);
        $catalogue = self::catalogue();
        if ($locale === 'en') {
            return response()->json(['data' => ['locale' => 'en', 'version' => $catalogue['version'], 'strings' => (object) []]]);
        }
        $strings = Cache::remember('app-strings.'.$locale.'.'.$catalogue['version'], now()->addMinutes(2),
            fn () => self::translate($translator, $locale));

        return response()->json(['data' => ['locale' => $locale, 'version' => $catalogue['version'], 'strings' => (object) $strings,
            'pending' => count($catalogue['strings']) - count($strings)]]);
    }

    /**
     * Known translations of the whole list; lines without one are queued for AI (in chunks, because one
     * lookup queues at most a few hundred new lines).
     *
     * @return array<string, string> English => translation
     */
    public static function translate(PageTranslator $translator, string $locale): array
    {
        $found = [];
        foreach (array_chunk(self::catalogue()['strings'], 300) as $chunk) {
            $found += $translator->lookup($chunk, $locale, learn: true);
        }

        return $found;
    }

    /** @return array{version: string, strings: list<string>} */
    public static function catalogue(): array
    {
        $path = resource_path('app/strings.json');
        $list = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        $list = array_values(array_unique(array_filter(is_array($list) ? $list : [], fn ($s) => is_string($s) && PageTranslator::normalise($s) === $s)));

        return ['version' => substr(sha1(implode("\n", $list)), 0, 12), 'strings' => $list];
    }
}
