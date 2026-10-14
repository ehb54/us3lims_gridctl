-- Queryable record of every pick_cluster() routing decision, so an operator
-- can answer "why did job X land on cluster Y" (or "why didn't it submit at
-- all") after the fact without grepping a flat log file.
--
-- Written by pick_cluster_reserve() (pick_cluster_reserve.php, this
-- directory), the one shared entry point both the autoflow (submitone.php)
-- and interactive (web/us3/lib/require_cluster.php) submission paths call.
-- See METASCHEDULER-DESIGN.md.
--
-- Purely additive: nothing reads this table to decide anything, so creating
-- it on a host that never configures a metascheduler pseudo-cluster entry
-- costs nothing and nothing ever writes to it either.
--
-- Retention: pick_cluster_record_decision() (pick_cluster_reserve.php, this
-- directory) opportunistically purges rows older than
-- decision_retention_seconds (default one year, see
-- pick_cluster_default_config(); override with
-- $global_metascheduler_decision_retention_seconds, 0 disables the purge)
-- on every write, the same piggyback pattern gfac.cluster_status_nudge uses.
-- This is still an audit trail -- the default is deliberately long so it
-- keeps answering "why did job X land on cluster Y" for a year of normal
-- operation -- just one that no longer grows forever by default.

USE gfac;

CREATE TABLE IF NOT EXISTS `metascheduler_decision` (
`id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'Unique decision ID',
`pseudo_cluster` varchar(120) NOT NULL COMMENT 'Metascheduler pool name that was resolved',
`chosen` varchar(120) DEFAULT NULL COMMENT 'Real cluster chosen, or NULL when none was eligible',
`reason` varchar(255) DEFAULT NULL COMMENT 'pick_cluster() reason string for the outcome',
`tier` varchar(32) DEFAULT NULL COMMENT 'Tier the chosen cluster belongs to (local/national/...), NULL when nothing chosen',
`scores` text COMMENT 'JSON-encoded pick_cluster() scores for every candidate, for full post-hoc diagnosis',
`time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When this decision was made',
PRIMARY KEY (`id`),
KEY `pseudo_cluster_time` (`pseudo_cluster`, `time`),
-- pick_cluster_record_decision()'s retention purge (pick_cluster_reserve.php)
-- deletes by `time` alone, with no pseudo_cluster predicate -- a composite
-- index led by pseudo_cluster cannot serve that range scan, forcing a full
-- table scan on every write (round-12 should-fix, gridctl#35: measured
-- 0.33s at 262k rows without this index, 0.000s with it).
KEY `time` (`time`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci
  COMMENT='Queryable history of metascheduler pick_cluster() decisions';
