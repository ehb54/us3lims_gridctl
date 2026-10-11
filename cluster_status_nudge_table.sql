-- Short-lived ledger of pick_cluster_reserve() decisions, read by
-- pick_cluster_status_snapshot() and added on top of the real cluster_status
-- probe row so two decisions made moments apart never both read the same
-- stale "idle" picture.
--
-- Separate from gfac.cluster_status itself -- which only a real probe
-- (cluster_status.php, cron, every 12 minutes by default) ever writes -- so
-- a nudge can expire on its own after nudge_ttl_seconds instead of
-- persisting, wrongly, until the next probe. See pick_cluster.php's
-- pick_cluster_record_nudge()/pick_cluster_nudge_counts() and
-- METASCHEDULER-DESIGN.md.
--
-- SELF-PURGING, NO RETENTION POLICY NEEDED. pick_cluster_record_nudge()
-- deletes every row older than nudge_ttl_seconds each time it writes a new
-- one, so this table never holds more than the last nudge_ttl_seconds worth
-- of decisions regardless of how long the deployment runs. Contrast with
-- gfac.metascheduler_decision (metascheduler_decision_table.sql, this
-- directory), the permanent audit trail, which has no such purge.
--
-- Purely additive: nothing reads this table to decide anything outside
-- pick_cluster_status_snapshot(), so creating it on a host that never
-- configures a metascheduler pseudo-cluster entry costs nothing and nothing
-- ever writes to it either.

CREATE TABLE IF NOT EXISTS `cluster_status_nudge` (
`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Unique nudge ID',
`cluster` varchar(64) NOT NULL COMMENT 'Real cluster this decision was nudged onto',
`time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When this decision was made',
PRIMARY KEY (`id`),
KEY `cluster_time` (`cluster`, `time`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci
  COMMENT='Short-lived pick_cluster_reserve() load nudges, independent of the real cluster_status probe';
