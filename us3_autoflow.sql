DROP TABLE IF EXISTS autoflowAnalysis;
CREATE TABLE autoflowAnalysis (
  requestID         int(11)      NOT NULL AUTO_INCREMENT,
  tripleName        text         NOT NULL,
  clusterDefault    text         DEFAULT "localhost",
  filename          text         NOT NULL,
  aprofileGUID      char(36)     NOT NULL,
  invID             int(11)      NOT NULL,
  currentGfacID     varchar(80)  DEFAULT NULL,
  statusJson        json,
  status            text         DEFAULT "unknown",
  statusMsg         text         DEFAULT "",
  stageSubmitTime   timestamp    DEFAULT NULL,
  createTime        timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updateTime        timestamp    DEFAULT 0 ON UPDATE CURRENT_TIMESTAMP,
  createUser        varchar(128) DEFAULT (current_user()),
  updateUser        varchar(128) DEFAULT "", # ON UPDATE (current_user()),

  PRIMARY KEY (RequestID)
  ) ENGINE=InnoDB;

DROP TABLE IF EXISTS autoflowAnalysisHistory;
CREATE TABLE autoflowAnalysisHistory (
  requestID         int(11)      NOT NULL AUTO_INCREMENT,
  tripleName        text         NOT NULL,
  clusterDefault    text         DEFAULT "localhost",
  filename          text         NOT NULL,
  aprofileGUID      char(36)     NOT NULL,
  invID             int(11)      NOT NULL,
  currentGfacID     varchar(80)  DEFAULT NULL,
  statusJson        json,
  status            text         DEFAULT "unknown",
  statusMsg         text         DEFAULT "",
  stageSubmitTime   timestamp    DEFAULT NULL,
  createTime        timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updateTime        timestamp    DEFAULT 0 ON UPDATE CURRENT_TIMESTAMP,
  createUser        varchar(128) DEFAULT (current_user()),
  updateUser        varchar(128) DEFAULT "", # ON UPDATE (current_user()),

  PRIMARY KEY (RequestID)
  ) ENGINE=InnoDB;

GRANT ALL ON gfac.* TO 'us3php'@'localhost';
GRANT SELECT, INSERT, DELETE, UPDATE ON `uslims3_%`.* to 'us3php'@'localhost';
