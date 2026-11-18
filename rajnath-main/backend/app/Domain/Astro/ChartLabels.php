<?php

namespace App\Domain\Astro;

/**
 * Planet abbreviations and the Lagna mark drawn inside the North Indian chart, in the page language. The page
 * translator never touches <svg> content, so the chart picks its labels here. Languages without a list use English.
 */
class ChartLabels
{
    /** @var array<string, array<string, string>> locale => planet key or "lagna" => label */
    public const SHORT = [
        'en' => ['sun' => 'Su', 'moon' => 'Mo', 'mars' => 'Ma', 'mercury' => 'Me', 'jupiter' => 'Ju', 'venus' => 'Ve', 'saturn' => 'Sa', 'rahu' => 'Ra', 'ketu' => 'Ke', 'lagna' => 'Asc', 'retro' => '(R)'],
        'hi' => ['sun' => 'सू', 'moon' => 'चं', 'mars' => 'मं', 'mercury' => 'बु', 'jupiter' => 'गु', 'venus' => 'शु', 'saturn' => 'श', 'rahu' => 'रा', 'ketu' => 'के', 'lagna' => 'लग्न', 'retro' => '(व)'],
        'mr' => ['sun' => 'र', 'moon' => 'चं', 'mars' => 'मं', 'mercury' => 'बु', 'jupiter' => 'गु', 'venus' => 'शु', 'saturn' => 'श', 'rahu' => 'रा', 'ketu' => 'के', 'lagna' => 'लग्न', 'retro' => '(व)'],
        'bn' => ['sun' => 'র', 'moon' => 'চ', 'mars' => 'ম', 'mercury' => 'বু', 'jupiter' => 'বৃ', 'venus' => 'শু', 'saturn' => 'শ', 'rahu' => 'রা', 'ketu' => 'কে', 'lagna' => 'লগ্ন', 'retro' => '(ব)'],
        'gu' => ['sun' => 'સૂ', 'moon' => 'ચં', 'mars' => 'મં', 'mercury' => 'બુ', 'jupiter' => 'ગુ', 'venus' => 'શુ', 'saturn' => 'શ', 'rahu' => 'રા', 'ketu' => 'કે', 'lagna' => 'લગ્ન', 'retro' => '(વ)'],
        'ta' => ['sun' => 'சூ', 'moon' => 'சந்', 'mars' => 'செ', 'mercury' => 'பு', 'jupiter' => 'கு', 'venus' => 'சு', 'saturn' => 'சனி', 'rahu' => 'ரா', 'ketu' => 'கே', 'lagna' => 'ல', 'retro' => '(வ)'],
        'te' => ['sun' => 'సూ', 'moon' => 'చం', 'mars' => 'కు', 'mercury' => 'బు', 'jupiter' => 'గు', 'venus' => 'శు', 'saturn' => 'శ', 'rahu' => 'రా', 'ketu' => 'కే', 'lagna' => 'ల', 'retro' => '(వ)'],
        'kn' => ['sun' => 'ರ', 'moon' => 'ಚಂ', 'mars' => 'ಕು', 'mercury' => 'ಬು', 'jupiter' => 'ಗು', 'venus' => 'ಶು', 'saturn' => 'ಶ', 'rahu' => 'ರಾ', 'ketu' => 'ಕೇ', 'lagna' => 'ಲ', 'retro' => '(ವ)'],
    ];

    /** @return array<string, string> */
    public static function for(?string $locale = null): array
    {
        return self::SHORT[$locale ?? app()->getLocale()] ?? self::SHORT['en'];
    }
}
