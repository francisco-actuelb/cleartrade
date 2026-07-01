<?php
// /var/www/ct.hsrv.fr/src/Services/SecFetcherService.php

namespace App\Services;

class SecFetcherService
{
    public function fetchNewFilings(?string $lastProcessedId): array
    {
        $url = 'https://www.sec.gov/cgi-bin/browse-edgar?action=getcurrent&type=4&company=&dateb=&owner=include&start=0&count=40&output=atom';

        $options = [
            'http' => [
                'header' => "User-Agent: ClearTrade (contact@hsrv.fr)\r\n"
            ]
        ];

        $context = stream_context_create($options);
        $rssContent = @file_get_contents($url, false, $context);

        if (!$rssContent) {
            return [];
        }

        $xml = new \SimpleXMLElement($rssContent);
        $entries = [];

        foreach ($xml->entry as $entry) {
            $rawId = (string)$entry->id;

            // CORRECTION : Extraction robuste du numéro d'accession à 20 chiffres
            if (preg_match('/([0-9]{10}-[0-9]{2}-[0-9]{6})/', $rawId, $matches)) {
                $accessionNumber = $matches[1];
            } else {
                // Fallback de sécurité au cas où le format SEC changerait
                $accessionNumber = preg_replace('/^urn:.*:/', '', $rawId);
            }

            if ($lastProcessedId && $accessionNumber === $lastProcessedId) {
                break;
            }

            $entries[] = $entry;
        }

        return array_reverse($entries);
    }

    public function fetchForm4Xml(string $indexUrl): ?\SimpleXMLElement
    {
        $txtUrl = str_replace('-index.htm', '.txt', $indexUrl);

        $options = [
            'http' => [
                'header' => "User-Agent: ClearTrade (contact@hsrv.fr)\r\n"
            ]
        ];
        $context = stream_context_create($options);

        $rawText = @file_get_contents($txtUrl, false, $context);

        if (!$rawText) {
            return null;
        }

        if (preg_match('/<XML>(.*?)<\/XML>/s', $rawText, $matches)) {
            $xmlString = trim($matches[1]);
            $xmlString = preg_replace('/<\?xml.*\?>/', '', $xmlString);

            try {
                return new \SimpleXMLElement($xmlString);
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }
}