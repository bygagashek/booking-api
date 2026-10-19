INSERT INTO restaurant_tables (id, name, seats) VALUES
    (1, 'Window 1', 2),
    (2, 'Window 2', 2),
    (3, 'Hall 1', 4),
    (4, 'Hall 2', 6)
ON DUPLICATE KEY UPDATE name = VALUES(name), seats = VALUES(seats);
