-- Endgame Tournaments — production seed
-- Users only. No test data, no tournaments, no teams.
-- Run: psql -U postgres -d endgame -f seed.sql

BEGIN;

TRUNCATE users RESTART IDENTITY CASCADE;

INSERT INTO users (username, display_name, password_hash, is_admin) VALUES
('admin',  'Admin',               '$2y$10$z9crlb5BoFtmCRcnDKFM0eTL9teH/iRqzp0VPmC/FHFIqMZlgETd.', true),
('marlene','Marlene Breiteneder', '$2y$10$R47fl6ch6NkzZdwDKYFSrehNIH.s52HdOEBDtVbM1NIt0DG.K3sGm', false),
('staff1', 'Staff 1',             '$2y$10$BDfM7Yo4YJ.sZdPZ/NNYK.QdO.V8B9ljfkofRzlNZrSgX6lFmETRW', false),
('staff2', 'Staff 2',             '$2y$10$BDfM7Yo4YJ.sZdPZ/NNYK.QdO.V8B9ljfkofRzlNZrSgX6lFmETRW', false),
('staff3', 'Staff 3',             '$2y$10$BDfM7Yo4YJ.sZdPZ/NNYK.QdO.V8B9ljfkofRzlNZrSgX6lFmETRW', false),
('staff4', 'Staff 4',             '$2y$10$BDfM7Yo4YJ.sZdPZ/NNYK.QdO.V8B9ljfkofRzlNZrSgX6lFmETRW', false),
('staff5', 'Staff 5',             '$2y$10$BDfM7Yo4YJ.sZdPZ/NNYK.QdO.V8B9ljfkofRzlNZrSgX6lFmETRW', false);

COMMIT;
