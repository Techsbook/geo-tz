<?php

declare(strict_types=1);

namespace GeoTz\Index;

/**
 * A quadtree index the Finder descends.
 *
 * Two backends exist: {@see PackedIndex} reads a fixed-layout binary with fseek
 * and holds almost nothing resident, and {@see ArrayIndex} wraps the original
 * json_decode'd structure. They must be indistinguishable to the Finder - the
 * packed format is a re-encoding of the same tree, not a different one.
 *
 * Nodes are addressed by an opaque reference whose meaning belongs to the
 * backend (a byte offset for the packed index, the sub-array itself for the
 * array index). Only the normalised shapes returned by node() are shared.
 */
interface Index
{
    /** Four children keyed a/b/c/d, any of which may be absent (open sea). */
    public const BRANCH = 0;

    /** A byte range of geo.dat holding the geobuf-encoded features for this quad. */
    public const FEATURE = 1;

    /** The quad is wholly inside one or more timezones; no geometry test needed. */
    public const ZONES = 2;

    /**
     * The root of the tree, already normalised.
     *
     * @return array{kind: int, children?: array<string, mixed>, pos?: int, len?: int, zones?: array<int, string>}
     */
    public function rootNode(): array;

    /**
     * Resolve a child reference handed out by a previous node().
     *
     * @return array{kind: int, children?: array<string, mixed>, pos?: int, len?: int, zones?: array<int, string>}
     */
    public function node(mixed $ref): array;

    /**
     * Visit every feature node in the tree, depth-first.
     *
     * The quad position handed to the callback is the concatenation of the
     * a/b/c/d steps taken to reach the node - the same string the Finder uses as
     * its feature-cache key, so a preloaded cache is interchangeable with one
     * filled by ordinary lookups.
     *
     * @param callable(string, int, int): void $visit (quadPos, pos, len)
     */
    public function eachFeatureNode(callable $visit): void;
}
