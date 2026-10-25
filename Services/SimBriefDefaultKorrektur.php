<?php

declare(strict_types=1);

namespace Modules\CoreFixes\Services;

use App\Models\Enums\AirframeSource;
use App\Models\SimBriefAircraft;
use App\Models\SimBriefAirframe;
use App\Services\SimBriefService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * FIX 9 — die „Default“-Profile des SimBrief-Imports gehören zum Grundtyp.
 *
 * # Das Problem
 *
 * `SimBriefService::getAircraftAndAirframes()` speichert jedes Airframe per
 * `updateOrCreate(['icao' => airframe_icao, 'name' => airframe_comments])`. SimBriefs
 * öffentliche Liste führt aber Typ-Varianten, deren „Default“ dieselbe ICAO trägt wie der
 * Grundtyp: BBJ2 und B738, E19L und E190, AC1T und AC11, B77F und B77L … Der zuletzt gelesene
 * Eintrag überschreibt den anderen. Gemessen am 25.10.2026: 20 doppelte Schlüssel, erklärt
 * 188 von 188 „Default“-Zeilen — der B738-„Default“ trug die 46 t Leergewicht der BBJ2, der
 * E190-„Default“ die 19 Sitze der Lineage.
 *
 * # Die Korrektur
 *
 * Derselbe Import wie im Core, mit EINEM Abruf der Liste (Codex 25.10.2026: ein zweiter Abruf
 * nach dem Core-Import konnte bei einem Netzaussetzer die falschen Defaults stehen lassen und
 * verdoppelte den Verkehr). Einziger Unterschied: das id-lose „Default“ einer Variante, deren
 * airframe_icao einem ANDEREN Typschlüssel gehört, wird nicht geschrieben — so bleibt die Zeile
 * des Grundtyps mit seinen Daten stehen. Gleiche Schlüssel wie im Core, also gleiche Zeilen-IDs;
 * nichts wird gelöscht. Typen ohne eigenen Grundtyp-Eintrag behalten das Core-Verhalten.
 * Ist die Liste nicht abrufbar, passiert wie im Core nichts.
 */
final class SimBriefDefaultKorrektur extends SimBriefService
{
    public function getAircraftAndAirframes(): void
    {
        $url = config('phpvms.simbrief_airframes_url');
        $sbdata = Http::get($url);

        if (! $sbdata->ok()) {
            Log::error('SimBrief | An Error Occured while trying to get aircraft and airframe data!');

            return;
        }
        Log::debug('SimBrief | Aircraft and Airframe data obtained');
        $json = (array) $sbdata->json();

        // ICAOs, für die es einen eigenen Grundtyp-Eintrag gibt (Typschlüssel = airframe_icao).
        $grundtypen = [];
        foreach ($json as $ac) {
            $grundtypen[strtoupper((string) ($ac['aircraft_icao'] ?? ''))] = true;
        }

        $uebersprungen = 0;
        foreach ($json as $ac) {
            $typSchluessel = strtoupper((string) ($ac['aircraft_icao'] ?? ''));
            foreach ((array) ($ac['airframes'] ?? []) as $af) {
                $icao = strtoupper((string) ($af['airframe_icao'] ?? ''));
                if ($af['airframe_id'] == false && $icao !== $typSchluessel && isset($grundtypen[$icao])) {
                    $uebersprungen++;
                    continue;   // „Default“ einer Variante — der Grundtyp schreibt seine eigenen Daten
                }
                SimBriefAirframe::updateOrCreate([
                    'icao' => $af['airframe_icao'],
                    'name' => $af['airframe_comments'],
                ], [
                    'icao'        => $af['airframe_icao'],
                    'name'        => $af['airframe_comments'],
                    'airframe_id' => ($af['airframe_id'] != false) ? $af['pilot_id'].'_'.$af['airframe_id'] : null,
                    'source'      => AirframeSource::SIMBRIEF,
                    'details'     => json_encode($af),
                    'options'     => json_encode($af['airframe_options']),
                ]);
            }

            unset($ac['airframes']);

            SimBriefAircraft::updateOrCreate([
                'icao' => $ac['aircraft_icao'],
            ], [
                'icao'    => $ac['aircraft_icao'],
                'name'    => $ac['aircraft_name'],
                'details' => json_encode($ac),
            ]);
        }

        Log::info('SimBrief | Import mit Default-Korrektur: '.$uebersprungen.' Varianten-Defaults uebersprungen');
    }
}
