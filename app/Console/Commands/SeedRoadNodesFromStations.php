<?php

namespace App\Console\Commands;

use App\Models\ChargingStation;
use App\Models\RoadNode;
use App\Services\RoadNodeLinker;
use Illuminate\Console\Command;

class SeedRoadNodesFromStations extends Command
{
    protected $signature = 'road-nodes:seed-from-stations {paths?* : Paths to station data files (defaults to both real seed files)}';

    protected $description = 'Idempotently create road_nodes rows (one per distinct township centroid, one per individual station) from the real station seed data, then link ANY charging_stations row still missing a road_node_id (including ones outside the seed files) back to its own station node';

    public function __construct(private readonly RoadNodeLinker $roadNodeLinker = new RoadNodeLinker)
    {
        parent::__construct();
    }

    /**
     * Townships that already have a road_nodes row (Yangon, Mandalay,
     * Naypyidaw) are matched by name + type so they're never duplicated —
     * this is the same find-or-create contract stations:seed-file already
     * established, just matched on a different identity (a place name
     * instead of name + coordinates, since a township has no coordinates
     * of its own in the source data).
     */
    private function findOrCreateCityNode(string $township, float $lat, float $lng): array
    {
        $existing = RoadNode::where('name', $township)->where('type', 'city')->first();

        if ($existing) {
            return [$existing, false];
        }

        $node = RoadNode::create([
            'name' => $township,
            'latitude' => $lat,
            'longitude' => $lng,
            'type' => 'city',
        ]);

        return [$node, true];
    }

    /**
     * Delegates to RoadNodeLinker - the same tolerant-coordinate-match
     * "find or create" contract this method used to implement directly is
     * now shared with ChargingStationController::store() and station-owner
     * registration (see RoadNodeLinker's own docblock), so this command no
     * longer carries its own separate copy of that SQL. $wasCreated is
     * derived from Eloquent's own wasRecentlyCreated flag rather than a
     * manual boolean, since RoadNodeLinker itself just returns the node.
     *
     * @return array{0: RoadNode, 1: bool}
     */
    private function findOrCreateStationNode(string $name, float $lat, float $lng): array
    {
        $node = $this->roadNodeLinker->findOrCreateStationNode($name, $lat, $lng);

        return [$node, $node->wasRecentlyCreated];
    }

    private function findMatchingStation(string $name, float $lat, float $lng): ?ChargingStation
    {
        return ChargingStation::where('name', $name)
            ->whereRaw('ROUND(latitude, 5) = ROUND(CAST(? AS DECIMAL(10,7)), 5)', [$lat])
            ->whereRaw('ROUND(longitude, 5) = ROUND(CAST(? AS DECIMAL(10,7)), 5)', [$lng])
            ->first();
    }

    public function handle(): int
    {
        $paths = $this->argument('paths');

        if (empty($paths)) {
            $paths = [
                database_path('seeders/data/stations_seed.php'),
                database_path('seeders/data/highway_16_seed.php'),
            ];
        }

        $entries = [];

        foreach ($paths as $path) {
            if (! is_file($path)) {
                $this->error("File not found: {$path}");

                return self::FAILURE;
            }

            $fileEntries = require $path;

            if (! is_array($fileEntries)) {
                $this->error("Data file did not return an array: {$path}");

                return self::FAILURE;
            }

            $entries = array_merge($entries, $fileEntries);
        }

        $this->info('Loaded '.count($entries)." station entries from ".count($paths).' file(s).');

        // --- Step 1: distinct townships, derived from the real data, not hand-typed ---
        $byTownship = collect($entries)
            ->filter(fn (array $e) => ! empty($e['township']))
            ->groupBy('township');

        $this->info("\nDistinct townships found in the data: {$byTownship->count()}");

        $cityNodesCreated = [];
        $cityNodesSkipped = 0;

        foreach ($byTownship as $township => $stationsInTownship) {
            $lat = $stationsInTownship->avg(fn (array $e) => (float) $e['latitude']);
            $lng = $stationsInTownship->avg(fn (array $e) => (float) $e['longitude']);

            [$node, $wasCreated] = $this->findOrCreateCityNode($township, $lat, $lng);

            if ($wasCreated) {
                $cityNodesCreated[] = $node;
                $this->line("CREATE city node (id={$node->id}): {$township} — centroid of {$stationsInTownship->count()} station(s) at {$lat}, {$lng}");
            } else {
                $cityNodesSkipped++;
                $this->line("SKIP  city node (already exists, id={$node->id}): {$township}");
            }
        }

        // --- Step 2: one station-type road_nodes row per real station, linked back ---
        $stationNodesCreated = 0;
        $stationNodesSkipped = 0;
        $linked = 0;
        $unmatched = [];

        foreach ($entries as $entry) {
            $name = $entry['name'];
            $lat = (float) $entry['latitude'];
            $lng = (float) $entry['longitude'];

            [$node, $wasCreated] = $this->findOrCreateStationNode($name, $lat, $lng);

            if ($wasCreated) {
                $stationNodesCreated++;
            } else {
                $stationNodesSkipped++;
            }

            $station = $this->findMatchingStation($name, $lat, $lng);

            if (! $station) {
                $unmatched[] = $name;
                $this->warn("No matching charging_stations row found for: {$name} — road_node created (id={$node->id}) but not linked");

                continue;
            }

            if ($station->road_node_id !== $node->id) {
                $station->update(['road_node_id' => $node->id]);
            }

            $linked++;
        }

        $this->info("\nCity nodes: created ".count($cityNodesCreated).", skipped (already existed) {$cityNodesSkipped}");
        $this->info("Station nodes: created {$stationNodesCreated}, skipped (already existed) {$stationNodesSkipped}");
        $this->info("Stations linked via road_node_id: {$linked} of ".count($entries));

        if (! empty($unmatched)) {
            $this->error('Unmatched entries (no charging_stations row found): '.implode(', ', $unmatched));
        }

        // --- Step 3: any charging_stations row still missing a road_node_id,
        // regardless of whether it came from a seed file — not hardcoded to
        // "the 5 legacy stations," so this keeps working automatically if
        // more stations are ever added outside the two seed files later
        // (e.g. a future admin "add station" UI). Reads straight from the
        // charging_stations table itself, not from any data file.
        $unlinkedStations = ChargingStation::whereNull('road_node_id')->get();

        $this->info("\nCharging stations still missing a road_node_id: {$unlinkedStations->count()}");

        $legacyNodesCreated = 0;
        $legacyNodesSkipped = 0;

        foreach ($unlinkedStations as $station) {
            [$node, $wasCreated] = $this->findOrCreateStationNode(
                $station->name,
                (float) $station->latitude,
                (float) $station->longitude,
            );

            if ($wasCreated) {
                $legacyNodesCreated++;
                $this->line("CREATE station node (id={$node->id}) for unlinked station: {$station->name}");
            } else {
                $legacyNodesSkipped++;
                $this->line("SKIP  station node (already exists, id={$node->id}) for unlinked station: {$station->name}");
            }

            $station->update(['road_node_id' => $node->id]);
        }

        $this->info("Legacy/other station nodes: created {$legacyNodesCreated}, skipped (already existed) {$legacyNodesSkipped}");
        $this->info('Previously-unlinked stations now linked: '.$unlinkedStations->count());

        return self::SUCCESS;
    }
}
