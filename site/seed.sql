-- Admin User Seed Data
-- Email: info@shanfixtechnology.com
-- Password: Sam@123@1s

INSERT INTO `users` (`full_name`, `email`, `password`, `role`, `status`) 
VALUES (
    'Shanfix Administrator', 
    'info@shanfixtechnology.com', 
    '$2y$10$AzUVKD/iX3kZhqUYgsry6OPuQg6hblSo6guCy.e/lQ9/y6sghrBXy', 
    'admin', 
    'active'
) 
ON DUPLICATE KEY UPDATE 
    `password` = VALUES(`password`),
    `role` = 'admin',
    `status` = 'active';
