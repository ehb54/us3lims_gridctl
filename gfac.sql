-- MySQL dump 10.13  Distrib 5.1.49, for pc-linux-gnu (i686)
--

--
-- Table structure for table analysis
--

DROP TABLE IF EXISTS analysis;
CREATE TABLE analysis (
  id int(11) NOT NULL AUTO_INCREMENT,
  gfacID varchar(80) DEFAULT NULL,
  cluster varchar(64) DEFAULT NULL,
  us3_db varchar(32) DEFAULT NULL,
  stdout longtext,
  stderr longtext,
  tarfile mediumblob,
  status enum('SUBMITTED','SUBMIT_TIMEOUT','RUNNING','RUN_TIMEOUT','DATA','DATA_TIMEOUT','COMPLETE','CANCELLED','CANCELED','FAILED','FAILED_DATA','ERROR') DEFAULT 'SUBMITTED',
  queue_msg text,
  time timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Table structure for table cluster_status
--

DROP TABLE IF EXISTS cluster_status;
CREATE TABLE cluster_status (
  cluster varchar(120) NOT NULL,
  queued int(11) DEFAULT NULL,
  running int(11) DEFAULT NULL,
  status enum('up','down','warn','unknown') DEFAULT 'up',
  time timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

--
-- Table structure for table queue_messages
--

DROP TABLE IF EXISTS queue_messages;
CREATE TABLE queue_messages (
  messageID int(11) NOT NULL AUTO_INCREMENT,
  analysisID int(11) NOT NULL,
  message text,
  time timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (messageID)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- Dump completed on 2012-01-23 15:52:19
