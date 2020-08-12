DROP TABLE IF EXISTS AutoflowAnalysis;
CREATE TABLE AutoflowAnalysis (
  ID                int(11)      NOT NULL AUTO_INCREMENT,
  TripleName        text         NOT NULL,
  us3_db_name       text         DEFAULT "unknown",
  Cluster_default   text         DEFAULT "localhost",
  Filename          text,
  AprofileGUID      char(36)     NOT NULL,

#  2DSA              tinyint(1)   NOT NULL DEFAULT 0,
#  2DSA_FM           tinyint(1)   NOT NULL DEFAULT 0,
#  FITMEN            tinyint(1)   NOT NULL DEFAULT 0,
#  2DSA_IT           tinyint(1)   NOT NULL DEFAULT 0,
#  2DSA_MC           tinyint(1)   NOT NULL DEFAULT 0,
  
  FinalStage        text         NOT NULL,
#  CurrentStage      ENUM('STARTING','2DSA','2DSA_FM','FITMEN','2DSA_IT','2DSA_MC','DONE') DEFAULT 'STARTING',
  CurrentGfacID     varchar(80)  DEFAULT NULL,

  status_json       json,

  status            text         DEFAULT "unknown",
  status_msg        text         DEFAULT "",
  create_time       timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  update_time       timestamp    DEFAULT 0 ON UPDATE CURRENT_TIMESTAMP,
  create_user       varchar(128) DEFAULT (current_user()),
  update_user       varchar(128) DEFAULT "", # ON UPDATE (current_user()),

  PRIMARY KEY (ID)
  ) ENGINE=MyISAM DEFAULT CHARSET=latin1;


DROP TABLE IF EXISTS AutoflowAnalysisHistory;
CREATE TABLE AutoflowAnalysisHistory (
  ID                int(11)      NOT NULL AUTO_INCREMENT,
  TripleName        text         NOT NULL,
  us3_db_name       text         DEFAULT "unknown",
  Cluster_default   text         DEFAULT "localhost",
  Filename          text,
  AprofileGUID      char(36)     NOT NULL,

#  2DSA              tinyint(1)   NOT NULL DEFAULT 0,
#  2DSA_FM           tinyint(1)   NOT NULL DEFAULT 0,
#  FITMEN            tinyint(1)   NOT NULL DEFAULT 0,
#  2DSA_IT           tinyint(1)   NOT NULL DEFAULT 0,
#  2DSA_MC           tinyint(1)   NOT NULL DEFAULT 0,
  
  FinalStage        text         NOT NULL,
#  CurrentStage      ENUM('STARTING','2DSA','2DSA_FM','FITMEN','2DSA_IT','2DSA_MC','DONE') DEFAULT 'STARTING',
  CurrentGfacID     varchar(80)  DEFAULT NULL,

  status_json       json,

  status            text         DEFAULT "unknown",
  status_msg        text         DEFAULT "",
  create_time       timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  update_time       timestamp    DEFAULT 0 ON UPDATE CURRENT_TIMESTAMP,
  create_user       varchar(128) DEFAULT (current_user()),
  update_user       varchar(128) DEFAULT "", # ON UPDATE (current_user()),

  PRIMARY KEY (ID)
  ) ENGINE=MyISAM DEFAULT CHARSET=latin1;

