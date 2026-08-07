<?php

namespace App\Console\Commands;

use App\Models\ChargingSlot;
use App\Models\ChargingStation;
use Illuminate\Console\Command;

class SeedChargingStationsFromFile extends Command
{
    protected $signature = 'stations:seed-file {path : Path to a PHP data file that returns an array of station entries}';

    protected $description = 'Idempotently insert charging stations (and their slots) from a data file, skipping any entry that already matches an existing station by name + coordinates';

    /**
     * Real-world identity for these entries is name + location, not an
     * auto-increment id the source data has no concept of. Coordinates are
     * compared after rounding both sides to 5 decimal places (~1 meter) in
     * SQL, since exact float equality would miss a true duplicate over a
     * trivial rounding difference between re-extractions of the same source
     * data. This can't be expressed as Eloquent's built-in firstOrCreate()
     * attribute array, since that only supports exact-equality WHERE
     * clauses — this method is the same find-or-create contract, applied
     * with a tolerant match.
     *
     * The bound coordinate must be CAST to DECIMAL before ROUND(), not
     * rounded as the bare PHP float — a bound float travels to MySQL as a
     * binary DOUBLE, and a value like 96.2518450 has no exact binary
     * representation, landing a hair under the true value. ROUND() on that
     * slightly-under DOUBLE rounds .25184|5 down to .25184, while ROUND()
     * on the DECIMAL column itself (exact fixed-point storage) correctly
     * rounds to .25185 — the mismatch silently missed a real duplicate
     * during verification (caught by re-running the 54-station file
     * against a database that already had every row: one entry,
     * "Samanea Market" at 16.8441170/96.2518450, landed on exactly this
     * boundary and got wrongly re-created before this cast was added).
     */
    private function findOrCreateStation(array $entry, int $slotCount, string $chargingSpeed): array
    {
        $lat = (float) $entry['latitude'];
        $lng = (float) $entry['longitude'];

        $existing = ChargingStation::where('name', $entry['name'])
            ->whereRaw('ROUND(latitude, 5) = ROUND(CAST(? AS DECIMAL(10,7)), 5)', [$lat])
            ->whereRaw('ROUND(longitude, 5) = ROUND(CAST(? AS DECIMAL(10,7)), 5)', [$lng])
            ->first();

        if ($existing) {
            return [$existing, false];
        }

        $station = ChargingStation::create([
            'owner_user_id' => null,
            'name' => $entry['name'],
            'latitude' => $lat,
            'longitude' => $lng,
            'address' => $entry['address'] ?? null,
            'township' => $entry['township'] ?? null,
            'total_slots' => $slotCount,
            'charging_speed' => $chargingSpeed,
            'operating_hours' => $entry['operating_hours'] ?? null,
            'verification_status' => $entry['verification_status'] ?? 'pending',
        ]);

        return [$station, true];
    }

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $entries = require $path;

        if (! is_array($entries)) {
            $this->error("Data file did not return an array: {$path}");

            return self::FAILURE;
        }

        $created = 0;
        $skipped = 0;

        foreach ($entries as $entry) {
            // Two source shapes: stations_seed.php gives one connector/power/kw
            // triple directly on the entry (always exactly 1 slot);
            // highway_16_seed.php gives an explicit 'slots' array of
            // [connector_type, power_type, power_kw] triples, including empty
            // arrays for stations with no confirmed slot data yet.
            $slotSpecs = $entry['slots'] ?? [[
                $entry['connector_type'],
                $entry['power_type'],
                $entry['power_kw'] ?? null,
            ]];

            $hasDc = collect($slotSpecs)->contains(fn ($slot) => $slot[1] === 'DC');
            $chargingSpeed = $hasDc ? 'fast' : 'standard';

            [$station, $wasCreated] = $this->findOrCreateStation($entry, count($slotSpecs), $chargingSpeed);

            if (! $wasCreated) {
                $skipped++;
                $this->line("SKIP  (already exists, id={$station->id}): {$entry['name']}");

                continue;
            }

            foreach ($slotSpecs as $i => [$connectorType, $powerType, $powerKw]) {
                ChargingSlot::create([
                    'station_id' => $station->id,
                    'slot_code' => chr(65 + $i).'1',
                    'connector_type' => $connectorType,
                    'power_type' => $powerType,
                    'power_kw' => $powerKw,
                    'status' => 'available',
                ]);
            }

            $created++;
            $this->line("CREATE (id={$station->id}): {$entry['name']}");
        }

        $this->info("Done. Created: {$created}, Skipped (already existed): {$skipped}");

        return self::SUCCESS;
    }
}
