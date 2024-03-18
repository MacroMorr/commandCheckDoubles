<?php

namespace App\Console\Commands;

use App\Models\Complex;
use Illuminate\Console\Command;

class CheckDoubles extends Command
{
    protected $signature = 'import:checkDoubles';

    protected $description = '';

    public function __construct()
    {
        parent::__construct();
    }

    public function clearStr($string): string
    {
        $string = mb_strtolower($string);
        $string = str_replace('-й', '', $string);
        $string = str_replace('клубный дом', '', $string);
        $string = str_replace('жилой дом', '', $string);
        $string = str_replace('микрорайон', '', $string);
        $string = str_replace('«', '', $string);
        $string = str_replace('»', '', $string);
        $string = preg_replace('/[.,\"]/', '', $string);
        $arrayString = explode(" ", $string);
        $exclude = [
            'жк',
            'жk',
            'ул',
            'по',
            'на',
            'дом',
            'дома',
            'жд',
            'бц',
            'кп',
            'с\х',
            'в',
        ];

        foreach ($arrayString as $s => $str) {
            if (in_array($str, $exclude)) {
                unset($arrayString[$s]);
            }
        }

        $string = implode(" ", $arrayString);
        $string = trim($string);

        return $string;
    }

    public function searchSimilarText($stringOne, $stringTwo): bool
    {
        $stringLength = mb_strlen($stringOne);
        $numberLevenshtein = levenshtein($stringOne, $stringTwo);

        $resCompute = $numberLevenshtein / $stringLength;

        if ($resCompute < 0.5) {
            return true;
        }
        return false;
    }

    public function checkException($complexName, $complexNameTwo): bool
    {
        $complexException = [
            ['навигатор', 'авиатор 2'],
            ['навигатор', 'авиатор'],

            ['масловский', 'маяковский'],

            ['гагаринский', 'гагаринский 2'],

            ['галактика', 'галактика 2'],
            ['галактика', 'галактика 3'],
            ['галактика', 'галактика 2|3'],
            ['галактика 2', 'галактика 3'],

            ['финский квартал', 'крымский квартал'],
            ['петровский квартал', 'крымский квартал'],

            ['гран-при', 'гран при 2'],
        ];

        foreach ($complexException as $exception) {
            if (
                ($exception[0] == $complexName && $exception[1] == $complexNameTwo) ||
                ($exception[1] == $complexName && $exception[0] == $complexNameTwo)
            ) {
                return true;
            }
        }
        return false;
    }

    public function handle()
    {
        $this->info('CheckDoubles start');

        $complexesWithProjects = Complex::has('projects')->get();

        $toRepeatedComplex = [];

        foreach ($complexesWithProjects as $complexOne) {
            foreach ($complexesWithProjects as $complexTwo) {
                if ($complexOne->source != $complexTwo->source) {
                    $clearComOne = $this->clearStr($complexOne->name);
                    $clearComTwo = $this->clearStr($complexTwo->name);
                    $isSimilar = $this->searchSimilarText($clearComOne, $clearComTwo);
                    $isException = $this->checkException($clearComOne, $clearComTwo);

                    if ($isSimilar && !$isException) {
                        $canAdd = true;
                        foreach ($toRepeatedComplex as $repeatedComplex) {
                            // Если эта пара комплексов уже есть в массиве, то canAdd = false (очерёдность не важна)
                            if (
                                ($repeatedComplex[0]['id'] == $complexOne->id && $repeatedComplex[1]['id'] == $complexTwo->id) ||
                                ($repeatedComplex[0]['id'] == $complexTwo->id && $repeatedComplex[1]['id'] == $complexOne->id)
                            ) {
                                $canAdd = false;
                            }
                        }

                        if ($canAdd) {
                            $toRepeatedComplex[] = [
                                [
                                    'id' => $complexOne->id,
                                    'name' => $complexOne->name,
                                    'source' => $complexOne->source
                                ],
                                [
                                    'id' => $complexTwo->id,
                                    'name' => $complexTwo->name,
                                    'source' => $complexTwo->source
                                ]
                            ];
                        }
                    }
                }
            }
        }

        if (!empty($toRepeatedComplex)) {
            $text = "Возможные дубли: \r\n";
            foreach ($toRepeatedComplex as $doubleComplex) {
                $text .= "id: " . $doubleComplex[0]['id'] . ", " . $doubleComplex[0]['name'] . ", " . $doubleComplex[0]['source'] . " | ";
                $text .= "id: " . $doubleComplex[1]['id'] . ", " . $doubleComplex[1]['name'] . ", " . $doubleComplex[1]['source'] . "\r\n";
            }

            if (env('VIBER_IMPORT_NOTIFY_1')) {
                $viber = new \App\Helpers\Viber;
                $viber->message_post(env('VIBER_IMPORT_NOTIFY_1'), ['name' => env('VIBER_FROM', '')], $text);
            }
            if (env('VIBER_IMPORT_NOTIFY_2')) {
                $viber = new \App\Helpers\Viber;
                $viber->message_post(env('VIBER_IMPORT_NOTIFY_2'), ['name' => env('VIBER_FROM', '')], $text);
            }
            if (env('VIBER_IMPORT_NOTIFY_3')) {
                $viber = new \App\Helpers\Viber;
                $viber->message_post(env('VIBER_IMPORT_NOTIFY_3'), ['name' => env('VIBER_FROM', '')], $text);
            }

            if (env('SLACK_NOVO_UPDATES')) {
                $text = "*" . env('SLACK_FROM', '') . "*\n" . $text;
                $ch = curl_init(env('SLACK_NOVO_UPDATES'));
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                curl_setopt($ch, CURLOPT_POSTFIELDS, "payload=" . json_encode(array("text" => $text)));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $isSimilar = curl_exec($ch);
                curl_close($ch);
            }
        }

        $this->info('CheckDoubles end');
    }
}

