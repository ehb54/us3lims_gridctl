DROP TABLE IF EXISTS AutoflowAnalysis;
CREATE TABLE AutoflowAnalysis (
  RequestID         int(11)      NOT NULL AUTO_INCREMENT,
  TripleName        text         NOT NULL,
  Cluster_default   text         DEFAULT "localhost",
  Filename          text,
  AprofileGUID      char(36)     NOT NULL,

  CurrentGfacID     varchar(80)  DEFAULT NULL,

  status_json       json,

  status            text         DEFAULT "unknown",
  status_msg        text         DEFAULT "",
  create_time       timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  update_time       timestamp    DEFAULT 0 ON UPDATE CURRENT_TIMESTAMP,
  create_user       varchar(128) DEFAULT (current_user()),
  update_user       varchar(128) DEFAULT "", # ON UPDATE (current_user()),

  PRIMARY KEY (RequestID)
  ) ENGINE=MyISAM DEFAULT CHARSET=latin1;


DROP TABLE IF EXISTS AutoflowAnalysisHistory;
CREATE TABLE AutoflowAnalysisHistory (
  RequestID         int(11)      NOT NULL AUTO_INCREMENT,
  TripleName        text         NOT NULL,
  Cluster_default   text         DEFAULT "localhost",
  Filename          text,
  AprofileGUID      char(36)     NOT NULL,

  CurrentGfacID     varchar(80)  DEFAULT NULL,

  status_json       json,

  status            text         DEFAULT "unknown",
  status_msg        text         DEFAULT "",
  create_time       timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  update_time       timestamp    DEFAULT 0 ON UPDATE CURRENT_TIMESTAMP,
  create_user       varchar(128) DEFAULT (current_user()),
  update_user       varchar(128) DEFAULT "", # ON UPDATE (current_user()),

  PRIMARY KEY (RequestID)
  ) ENGINE=MyISAM DEFAULT CHARSET=latin1;

GRANT ALL ON gfac.* TO 'us3php'@'localhost';
GRANT SELECT, INSERT, DELETE, UPDATE ON `uslims3_%`.* to 'us3php'@'localhost';
