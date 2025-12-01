-- Quick reply prompts and auto-reply system for HanapBahay chat

-- Table for predefined quick reply prompts
CREATE TABLE IF NOT EXISTS `chat_quick_replies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `message` varchar(255) NOT NULL,
  `category` varchar(50) DEFAULT 'general',
  `display_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table for auto-reply patterns and responses
CREATE TABLE IF NOT EXISTS `chat_auto_replies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `trigger_pattern` varchar(255) NOT NULL,
  `response_message` text NOT NULL,
  `category` varchar(50) DEFAULT 'general',
  `is_active` tinyint(1) DEFAULT 1,
  `match_type` enum('contains', 'starts_with', 'exact', 'regex') DEFAULT 'contains',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Insert default quick reply prompts
INSERT INTO `chat_quick_replies` (`message`, `category`, `display_order`) VALUES
('What services do you offer?', 'services', 1),
('Can I schedule a viewing?', 'viewing', 2),
('What are your rates?', 'pricing', 3),
('Is parking available?', 'amenities', 4),
('What utilities are included?', 'utilities', 5),
('Are pets allowed?', 'policies', 6);

-- Insert default auto-reply patterns
INSERT INTO `chat_auto_replies` (`trigger_pattern`, `response_message`, `category`, `match_type`) VALUES
('services', 'We offer residential rental services including property viewing, application processing, and tenant support. Our properties range from studio units to family homes with various amenities.', 'services', 'contains'),
('viewing', 'I can help arrange a property viewing! Please let me know your preferred date and time, and I''ll check availability with the property owner.', 'viewing', 'contains'),
('schedule', 'I can help arrange a property viewing! Please let me know your preferred date and time, and I''ll check availability with the property owner.', 'viewing', 'contains'),
('rates', 'Property rates vary depending on location, size, and amenities. You can view specific pricing details on each property listing. Would you like information about a particular property?', 'pricing', 'contains'),
('price', 'Property rates vary depending on location, size, and amenities. You can view specific pricing details on each property listing. Would you like information about a particular property?', 'pricing', 'contains'),
('parking', 'Parking availability varies by property. Some include dedicated parking spaces while others may have street parking. Please check the specific property details or ask about a particular listing.', 'amenities', 'contains'),
('utilities', 'Utility inclusions vary by property. Some rentals include water and electricity, while others may be separate. Please check the property details or ask about specific utilities for the property you''re interested in.', 'utilities', 'contains'),
('pets', 'Pet policies vary by property and owner preference. Some properties welcome pets while others don''t allow them. Please ask about the specific pet policy for the property you''re interested in.', 'policies', 'contains'),
('hello', 'Hello! Welcome to HanapBahay. How can I help you find your ideal rental property today?', 'greeting', 'contains'),
('hi', 'Hi there! Welcome to HanapBahay. How can I help you find your ideal rental property today?', 'greeting', 'contains'),
('thank you', 'You''re welcome! Feel free to ask if you have any other questions about our rental properties.', 'courtesy', 'contains'),
('thanks', 'You''re welcome! Feel free to ask if you have any other questions about our rental properties.', 'courtesy', 'contains');