DROP TRIGGER IF EXISTS delete_binaries;

CREATE TRIGGER delete_binaries BEFORE DELETE ON binaries FOR EACH ROW BEGIN DELETE FROM parts WHERE binaryid = OLD.id; END;

UPDATE tmux set value = '100' where setting = 'sqlpatch';