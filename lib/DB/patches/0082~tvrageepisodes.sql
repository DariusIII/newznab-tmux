DROP TABLE IF EXISTS tvrageepisodes;
CREATE TABLE         tvrageepisodes (
  id        INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  rageid    INT(11) UNSIGNED NOT NULL,
  showtitle VARCHAR(255)     DEFAULT NULL,
  airdate   DATETIME         NOT NULL,
  link      VARCHAR(255)     DEFAULT NULL,
  fullep    VARCHAR(20)      NOT NULL,
  eptitle   VARCHAR(255)     DEFAULT NULL,
  PRIMARY KEY                           (id),
  UNIQUE INDEX ix_tvrageepisodes_rageid (rageid, fullep)
)
  ENGINE          = INNODB
  DEFAULT CHARSET = utf8
  COLLATE         = utf8_unicode_ci
  AUTO_INCREMENT  = 1;
UPDATE `tmux` SET `value` = '82' WHERE `setting` = 'sqlpatch';