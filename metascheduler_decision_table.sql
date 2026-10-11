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
-- NO RETENTION POLICY. Unlike gfac.cluster_status_nudge (self-purging, see
-- cluster_status_nudge_table.sql), this is an audit trail and grows one row
-- per submission for the life of the deployment. An operator who wants to
-- bound it prunes by `time` by hand; nothing in this codebase does it
-- automatically, on purpose -- this table's whole job is to answer "why",
-- after the fact, for as long as that answer is wanted.

CREATE TABLE IF NOT EXISTS `metascheduler_decision` (
`id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'Unique decision ID',
`pseudo_cluster` varchar(120) NOT NULL COMMENT 'Metascheduler pool name that was resolved',
`chosen` varchar(120) DEFAULT NULL COMMENT 'Real cluster chosen, or NULL when none was eligible',
`reason` varchar(255) DEFAULT NULL COMMENT 'pick_cluster() reason string for the outcome',
`tier` varchar(32) DEFAULT NULL COMMENT 'Tier the chosen cluster belongs to (local/national/...), NULL when nothing chosen',
`scores` text COMMENT 'JSON-encoded pick_cluster() scores for every candidate, for full post-hoc diagnosis',
`time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When this decision was made',
PRIMARY KEY (`id`),
KEY `pseudo_cluster_time` (`pseudo_cluster`, `time`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci
  COMMENT='Queryable history of metascheduler pick_cluster() decisions';
