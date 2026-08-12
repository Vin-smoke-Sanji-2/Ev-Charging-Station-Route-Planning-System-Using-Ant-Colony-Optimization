<?php

namespace App\Console\Commands;

use App\Models\RoadEdge;
use Illuminate\Console\Command;

class SeedRoadEdgesFromFile extends Command
{
    protected $signature = 'road-edges:seed-from-file {path? : Path to a PHP data file that returns an array of edge entries (defaults to the real computed export)}';

    protected $description = 'Idempotently insert road_edges rows from a computed edge-data file, skipping any entry that already matches an existing edge by from_node_id + to_node_id';

    /**
     * Unlike stations/road-nodes matching (which need coordinate
     * tolerance), an edge's identity here is just its two real
     * road_nodes.id foreign keys — exact integer equality is the
     * correct match, no rounding needed.
     */
    private function findOrCreateEdge(array $entry): array
    {
        $existing = RoadEdge::where('from_node_id', $entry['from_node_id'])
            ->where('to_node_id', $entry['to_node_id'])
            ->first();

        if ($existing) {
            return [$existing, false];
        }

        $edge = RoadEdge::create([
            'from_node_id' => $entry['from_node_id'],
            'to_node_id' => $entry['to_node_id'],
            'distance_km' => $entry['distance_km'],
            'avg_speed_kmh' => $entry['avg_speed_kmh'],
            'geometry' => $entry['geometry'],
        ]);

        return [$edge, true];
    }

    public function handle(): int
    {
        $path = $this->argument('path') ?? database_path('seeders/data/road_edges_seed.php');

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
            [$edge, $wasCreated] = $this->findOrCreateEdge($entry);

            $fromName = $entry['from_node_name'] ?? $entry['from_node_id'];
            $toName = $entry['to_node_name'] ?? $entry['to_node_id'];

            if ($wasCreated) {
                $created++;
                $this->line("CREATE (id={$edge->id}): {$fromName} -> {$toName} ({$entry['distance_km']}km)");
            } else {
                $skipped++;
                $this->line("SKIP  (already exists, id={$edge->id}): {$fromName} -> {$toName}");
            }
        }

        $this->info("Done. Created: {$created}, Skipped (already existed): {$skipped}");

        return self::SUCCESS;
    }
}
