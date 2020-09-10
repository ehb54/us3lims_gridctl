# seriously crippled version for obsolete mysql versions

DROP TABLE IF EXISTS autoflowAnalysis;
CREATE TABLE autoflowAnalysis (
  requestID         int(11)      NOT NULL AUTO_INCREMENT,
  tripleName        text         NOT NULL,
  clusterDefault    text         ,
  filename          text         NOT NULL,
  aprofileGUID      char(36)     NOT NULL,
  invID             int(11)      NOT NULL,
  currentGfacID     varchar(80)  DEFAULT NULL,
  currentHPCARID    int(11)      DEFAULT NULL,
  statusJson        longtext     ,
  status            text         ,
  statusMsg         text         ,
  stageSubmitTime   timestamp    DEFAULT NULL,
  createTime        timestamp    DEFAULT CURRENT_TIMESTAMP,
  updateTime        timestamp    ,
  createUser        varchar(128) ,
  updateUser        varchar(128) ,

  PRIMARY KEY (RequestID)
  ) ENGINE=InnoDB;


DROP TABLE IF EXISTS autoflowAnalysisHistory;
CREATE TABLE autoflowAnalysisHistory (
  requestID         int(11)      NOT NULL UNIQUE,
  tripleName        text         NOT NULL,
  clusterDefault    text         ,
  filename          text         NOT NULL,
  aprofileGUID      char(36)     NOT NULL,
  invID             int(11)      NOT NULL,
  currentGfacID     varchar(80)  DEFAULT NULL,
  currentHPCARID    int(11)      DEFAULT NULL,
  statusJson        longtext     ,
  status            text         ,
  statusMsg         text         ,
  stageSubmitTime   timestamp    DEFAULT NULL,
  createTime        timestamp    DEFAULT CURRENT_TIMESTAMP,
  updateTime        timestamp    ,
  createUser        varchar(128) ,
  updateUser        varchar(128) ,

  PRIMARY KEY (RequestID)
  ) ENGINE=InnoDB;

GRANT ALL ON gfac.* TO 'us3php'@'localhost';
GRANT SELECT, INSERT, DELETE, UPDATE ON `uslims3_%`.* to 'us3php'@'localhost';
