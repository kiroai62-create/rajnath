<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Ads;
use Illuminate\Http\Response;

/** /ads.txt (Admin › Google & ads): the AdSense line plus the lines other ad networks ask for; 404 when empty. */
class AdsTxtController extends Controller
{
    public function __invoke(Ads $ads): Response
    {
        $txt = $ads->adsTxt();
        abort_unless($txt, 404);

        return response($txt, 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'public, max-age=3600']);
    }
}
