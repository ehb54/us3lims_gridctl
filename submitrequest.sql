--
-- Table structure for table submit_request, submit_request_history
--

USE gfac;

DROP TABLE IF EXISTS submit_request;
CREATE TABLE submit_request (

  requestID     int(11)      NOT NULL AUTO_INCREMENT,
  status        text         DEFAULT "unknown",
  status_msg    text         DEFAULT "",
  lims_db       text         DEFAULT "unknown",
  create_time   timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  update_time   timestamp    DEFAULT 0 ON UPDATE CURRENT_TIMESTAMP,
  create_user   varchar(128) DEFAULT (current_user()),
  update_user   varchar(128) DEFAULT "", # ON UPDATE (current_user()),
  requestXMLFile longtext    DEFAULT NULL,

  PRIMARY KEY (requestID)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;


DROP TABLE IF EXISTS submit_request_history;
CREATE TABLE submit_request_history (

  requestID     int(11)      UNIQUE,
  status        text         DEFAULT "unknown",
  status_msg    text         DEFAULT "",
  lims_db       text         DEFAULT "unknown",
  create_time   timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  update_time   timestamp    DEFAULT 0 ON UPDATE CURRENT_TIMESTAMP,
  create_user   varchar(128) DEFAULT (current_user()),
  update_user   varchar(128) DEFAULT "", # ON UPDATE (current_user()),
  requestXMLFile longtext    DEFAULT NULL,

  PRIMARY KEY (requestID)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;


