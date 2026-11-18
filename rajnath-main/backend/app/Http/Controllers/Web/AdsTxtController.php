<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Google;
use Illuminate\Http\Response;

/** /ads.txt for Google AdSense (Admin › Google & ads); 404 until a publisher id is saved. */
class AdsTxtController extends Controller
{
    public function __invoke(Google $google): Response
    {
        $txt = $google->adsTxt();
        abort_unless($txt, 404);

        return response($txt, 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'public, max-age=3600']);
    }
}
