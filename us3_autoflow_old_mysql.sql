# seriously crippled version for obsolete mysql versions

DROP TABLE IF EXISTS AutoflowAnalysis;
CREATE TABLE AutoflowAnalysis (
  RequestID         int(11)      NOT NULL AUTO_INCREMENT,
  TripleName        text         NOT NULL,
  Cluster_default   text         ,
  Filename          text,
  AprofileGUID      char(36)     NOT NULL,

  CurrentGfacID     varchar(80)  DEFAULT NULL,

  status_json       longtext     ,

  status            text         ,
  status_msg        text         ,
  create_time       timestamp    DEFAULT CURRENT_TIMESTAMP,
  update_time       timestamp    ,
  create_user       varchar(128) ,
  update_user       varchar(128) ,

  PRIMARY KEY (RequestID)
  ) ENGINE=MyISAM DEFAULT CHARSET=latin1;


DROP TABLE IF EXISTS AutoflowAnalysisHistory;
CREATE TABLE AutoflowAnalysisHistory (
  RequestID         int(11)      NOT NULL AUTO_INCREMENT,
  TripleName        text         NOT NULL,
  Cluster_default   text         ,
  Filename          text,
  AprofileGUID      char(36)     NOT NULL,
  
  CurrentGfacID     varchar(80)  DEFAULT NULL,

  status_json       longtext     ,

  status            text         ,
  status_msg        text         ,
  create_time       timestamp    DEFAULT CURRENT_TIMESTAMP,
  update_time       timestamp    ,
  create_user       varchar(128) ,
  update_user       varchar(128) ,

  PRIMARY KEY (RequestID)
  ) ENGINE=MyISAM DEFAULT CHARSET=latin1;

GRANT ALL ON gfac.* TO 'us3php'@'localhost';
GRANT SELECT, INSERT, DELETE, UPDATE ON `uslims3_%`.* to 'us3php'@'localhost';
