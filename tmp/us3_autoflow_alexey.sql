DROP TABLE IF EXISTS autoflow;

CREATE  TABLE IF NOT EXISTS autoflow (
  ID int(11) NOT NULL AUTO_INCREMENT ,
  protName varchar(80) NULL,
  cellChNum varchar(80) NULL,
  tripleNum varchar(80) NULL,
  duration int(10)  NULL,
  runName varchar(300) NULL,
  expID  int(10) NULL,
  runID  int(10) NULL,
  status enum('LIVE_UPDATE','EDITING','EDIT_DATA','ANALYSIS','REPORT') NOT NULL,
  dataPath varchar(300) NULL,
  optimaName varchar(300) NULL,
  runStarted TIMESTAMP NULL,
  invID  INT NULL,
  created TIMESTAMP NULL,
  corrRadii enum('YES', 'NO') NOT NULL,
  expAborted enum('NO', 'YES') NOT NULL,
  label varchar(80) NULL,
  gmpRun enum ('NO', 'YES') NOT	NULL,
  filename varchar(300) NULL,
  aprofileGUID varchar(80) NULL,
  PRIMARY KEY (ID) )
ENGINE = InnoDB;
