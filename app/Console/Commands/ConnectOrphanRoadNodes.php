<?php

namespace App\Console\Commands;

use App\Models\RoadNode;
use App\Services\RoadNodeLinker;
use Illuminate\Console\Command;

/**
 * One-off backfill for road_nodes rows created before RoadNodeLinker
 * started connecting new stations to the graph automatically (see that
 * class's own connectToGraph()/connectOrphanNodes() docs for the full
 * root-cause writeup of the Navigate "stays on the dashed fallback
 * forever" bug this closes). Safe to run repeatedly - a node with at
 * least one road_edges row is never selected again.
 */
class ConnectOrphanRoadNodes extends Command
{
    protected $signature = 'road-nodes:connect-orphans';

    protected $description = 'Connect any road_nodes row with zero road_edges to its nearest already-connected node via a straight-line connector edge pair';

    public function handle(RoadNodeLinker $linker): int
    {
        $orphanNames = RoadNode::query()
            ->whereDoesntHave('outgoingEdges')
            ->whereDoesntHave('incomingEdges')
            ->pluck('name', 'id');

        if ($orphanNames->isEmpty()) {
            $this->info('No orphan road_nodes found - nothing to do.');

            return self::SUCCESS;
        }

        foreach ($orphanNames as $id => $name) {
            $this->line("Connecting: {$name} (id={$id})");
        }

        $count = $linker->connectOrphanNodes();

        $this->info("Done. Connected {$count} orphan node(s) to the graph.");

        return self::SUCCESS;
    }
}
