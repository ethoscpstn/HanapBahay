<?php
/**
 * Password Strength Validation
 * Add this to your registration and password change forms
 */

require_once 'includes/env_loader.php';

function validatePasswordStrength($password) {
    $min_length = EnvLoader::get('PASSWORD_MIN_LENGTH', 8);
    
    $errors = [];
    
    // Check minimum length
    if (strlen($password) < $min_length) {
        $errors[] = "Password must be at least $min_length characters long";
    }
    
    // Check for uppercase letter
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter";
    }
    
    // Check for lowercase letter
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter";
    }
    
    // Check for number
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number";
    }
    
    // Check for special character
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = "Password must contain at least one special character";
    }
    
    // Check for common passwords
    $common_passwords = ['password', '123456', 'qwerty', 'abc123', 'password123'];
    if (in_array(strtolower($password), $common_passwords)) {
        $errors[] = "Password is too common. Please choose a more secure password";
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}

function getPasswordStrengthScore($password) {
    $score = 0;
    
    // Length bonus
    if (strlen($password) >= 8) $score += 1;
    if (strlen($password) >= 12) $score += 1;
    if (strlen($password) >= 16) $score += 1;
    
    // Character type bonuses
    if (preg_match('/[A-Z]/', $password)) $score += 1;
    if (preg_match('/[a-z]/', $password)) $score += 1;
    if (preg_match('/[0-9]/', $password)) $score += 1;
    if (preg_match('/[^A-Za-z0-9]/', $password)) $score += 1;
    
    // Complexity bonus
    if (preg_match('/[^A-Za-z0-9]/', $password)) $score += 1;
    
    return min($score, 5); // Max score of 5
}

function getPasswordStrengthText($score) {
    switch ($score) {
        case 0:
        case 1:
            return ['text' => 'Very Weak', 'color' => 'red'];
        case 2:
            return ['text' => 'Weak', 'color' => 'orange'];
        case 3:
            return ['text' => 'Fair', 'color' => 'yellow'];
        case 4:
            return ['text' => 'Good', 'color' => 'lightgreen'];
        case 5:
            return ['text' => 'Strong', 'color' => 'green'];
        default:
            return ['text' => 'Unknown', 'color' => 'gray'];
    }
}

// Usage example:
// $validation = validatePasswordStrength($password);
// if (!$validation['valid']) {
//     foreach ($validation['errors'] as $error) {
//         echo "<p style='color: red;'>$error</p>";
//     }
// }

?>





