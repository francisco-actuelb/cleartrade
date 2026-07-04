<?php
// /var/www/ct.hsrv.fr/src/Services/CompanyProfileService.php

namespace App\Services;

class CompanyProfileService
{
    private string $finnhubKey;
    private string $yahooCookie = '';
    private string $yahooCrumb = '';
    private bool $yahooInitialized = false;

    public function __construct(string $finnhubKey)
    {
        $this->finnhubKey = $finnhubKey;
    }

    /**
     * Initialise la session Yahoo (Cookie + Crumb) une seule fois pour tout le lot.
     */
    private function initYahooAuth(): void
    {
        if ($this->yahooInitialized) return;

        $chCookie = curl_init('https://fc.yahoo.com');
        curl_setopt($chCookie, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chCookie, CURLOPT_HEADER, true);
        curl_setopt($chCookie, CURLOPT_SSL_VERIFYPEER, false);
        $resCookie = curl_exec($chCookie);
        curl_close($chCookie);

        if (preg_match('/^Set-Cookie:\s*(A3=[^;]*)/mi', $resCookie, $matches)) {
            $this->yahooCookie = $matches[1];
        } elseif (preg_match('/^Set-Cookie:\s*([^;]*)/mi', $resCookie, $matches)) {
            $this->yahooCookie = $matches[1];
        }

        if ($this->yahooCookie) {
            $chCrumb = curl_init('https://query1.finance.yahoo.com/v1/test/getcrumb');
            curl_setopt($chCrumb, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chCrumb, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($chCrumb, CURLOPT_HTTPHEADER, ["Cookie: " . $this->yahooCookie]);
            curl_setopt($chCrumb, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
            $this->yahooCrumb = curl_exec($chCrumb);
            curl_close($chCrumb);
        }

        $this->yahooInitialized = true;
    }

    /**
     * Cherche le profil de l'entreprise en testant les fournisseurs en cascade.
     */
    public function getProfile(string $ticker): ?array
    {
        $this->initYahooAuth();

        // 1. Fournisseur principal : Yahoo Finance
        $profile = $this->fetchFromYahoo($ticker);
        if ($profile !== null) {
            return $profile;
        }

        // 2. Fallback 1 : Finnhub
        if (!empty($this->finnhubKey)) {
            $profile = $this->fetchFromFinnhub($ticker);
            if ($profile !== null) {
                return $profile;
            }
        }

        // 3. (Espace prêt pour de futurs fallbacks...)

        return null; // Aucun fournisseur n'a trouvé l'entreprise
    }

    private function fetchFromYahoo(string $ticker): ?array
    {
        $metaUrl = "https://query2.finance.yahoo.com/v10/finance/quoteSummary/{$ticker}?modules=assetProfile,defaultKeyStatistics&crumb={$this->yahooCrumb}";
        $chMeta = curl_init($metaUrl);
        curl_setopt($chMeta, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chMeta, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($chMeta, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko)');

        if ($this->yahooCookie) {
            curl_setopt($chMeta, CURLOPT_HTTPHEADER, ["Cookie: " . $this->yahooCookie]);
        }

        $metaResponse = curl_exec($chMeta);
        $httpCode = curl_getinfo($chMeta, CURLINFO_HTTP_CODE);
        curl_close($chMeta);

        if ($httpCode === 200 && $metaResponse) {
            $metaData = json_decode($metaResponse, true);
            $sector = $metaData['quoteSummary']['result'][0]['assetProfile']['sector'] ?? null;
            $industry = $metaData['quoteSummary']['result'][0]['assetProfile']['industry'] ?? null;
            $sharesOutstanding = $metaData['quoteSummary']['result'][0]['defaultKeyStatistics']['sharesOutstanding']['raw'] ?? null;

            if ($sector || $sharesOutstanding || $industry) {
                return [
                    'source' => 'Yahoo',
                    'sector' => $sector,
                    'industry' => $industry,
                    'shares_outstanding' => $sharesOutstanding
                ];
            }
        }
        return null;
    }

    private function fetchFromFinnhub(string $ticker): ?array
    {
        $url = "https://finnhub.io/api/v1/stock/profile2?symbol={$ticker}&token={$this->finnhubKey}";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);

            if (!empty($data) && isset($data['finnhubIndustry'])) {
                $industry = $data['finnhubIndustry'];
                $sharesOutstanding = isset($data['shareOutstanding']) ? (int)($data['shareOutstanding'] * 1000000) : null;

                return [
                    'source' => 'Finnhub',
                    'sector' => $industry, // Finnhub ne sépare pas les deux, on duplique
                    'industry' => $industry . ' (Via Finnhub)',
                    'shares_outstanding' => $sharesOutstanding
                ];
            }
        }

        // Protection pour le Rate Limit Finnhub au cas où il serait appelé souvent
        usleep(1000000);
        return null;
    }
}