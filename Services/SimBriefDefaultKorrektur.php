<?php

declare(strict_types=1);

namespace Modules\CoreFixes\Services;

use App\Models\SimBriefAirframe;
use App\Services\SimBriefService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

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
 * Nach dem Import werden nur die „Default“-Zeilen neu geschrieben, und zwar mit den Daten des
 * Eintrags, dessen äußerer Typschlüssel gleich seiner airframe_icao ist (der Grundtyp). Es wird
 * nichts gelöscht und nichts neu angelegt; die Zeilen-IDs bleiben (PaxStudio adressiert
 * „Default“ über die Zeilen-ID). Varianten bekommen keine eigene Zeile — phpVMS kann zwei
 * „Default“ je ICAO nicht unterscheiden. Ist die Liste nicht abrufbar oder unplausibel klein,
 * passiert nichts.
 */
final class SimBriefDefaultKorrektur extends SimBriefService
{
    /** Weniger Typen als das ist eine halbe Antwort, kein SimBrief-Katalog (25.10.2026: ~400). */
    private const MINDESTUMFANG = 100;

    public function getAircraftAndAirframes(): void
    {
        parent::getAircraftAndAirframes();

        try {
            $antwort = Http::timeout(60)->get(config('phpvms.simbrief_airframes_url'));
            $liste = $antwort->ok() ? (array) $antwort->json() : [];
            if (count($liste) < self::MINDESTUMFANG) {
                Log::warning('SimBrief | Default-Korrektur uebersprungen: Liste nicht abrufbar oder zu klein ('.count($liste).')');

                return;
            }

            $korrigiert = 0;
            foreach ($liste as $schluessel => $typ) {
                $typSchluessel = strtoupper(trim((string) ($typ['aircraft_icao'] ?? $schluessel)));
                foreach ((array) ($typ['airframes'] ?? []) as $af) {
                    $icao = strtoupper(trim((string) ($af['airframe_icao'] ?? '')));
                    if (! empty($af['airframe_id']) || $icao !== $typSchluessel) {
                        continue;   // nur das id-lose „Default“ des Grundtyps
                    }
                    $zeile = SimBriefAirframe::where('icao', $af['airframe_icao'])
                        ->where('name', $af['airframe_comments'])
                        ->where(fn ($q) => $q->whereNull('airframe_id')->orWhere('airframe_id', ''))
                        ->first();
                    if ($zeile === null) {
                        continue;
                    }
                    $optionen = json_encode($af['airframe_options'] ?? null);
                    if ((string) $zeile->options === $optionen) {
                        continue;
                    }
                    $zeile->details = json_encode($af);
                    $zeile->options = $optionen;
                    $zeile->save();
                    $korrigiert++;
                }
            }
            Log::info('SimBrief | Default-Korrektur: '.$korrigiert.' Default-Profile auf den Grundtyp gesetzt');
        } catch (Throwable $e) {
            Log::warning('SimBrief | Default-Korrektur fehlgeschlagen: '.$e->getMessage());
        }
    }
}
