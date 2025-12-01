<?php
// includes/navigation.php - Shared navigation component
session_start();

// Homepage navigation - always shows guest navigation
function getHomepageNavigation() {
    // Dark mode toggle button
    $darkModeToggle = '
    <button id="darkModeToggle" class="btn btn-outline-light btn-sm me-2" title="Toggle Dark Mode">
        <i class="bi bi-moon-fill" id="darkModeIcon"></i>
    </button>';
    
    // Always show guest navigation for homepage
    return '
    <nav class="topFixedBar">
        <div class="container d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center">
                <img src="Assets/Logo1.png" alt="HanapBahay Logo" class="logo" />
            </div>
            <div class="d-flex align-items-center gap-2">
                ' . $darkModeToggle . '
                <a href="index.php" class="btn btn-outline-light btn-sm">Home</a>
                <a href="browse_listings.php" class="btn btn-outline-light btn-sm">Browse</a>
                <a href="LoginModule.php" class="btn btn-warning btn-sm text-dark">Login</a>
                <a href="LoginModule.php?register=1" class="btn btn-warning btn-sm text-dark">Register</a>
            </div>
        </div>
    </nav>';
}

function getNavigationForRole($currentPage = '') {
    $role = $_SESSION['role'] ?? '';
    $isLoggedIn = isset($_SESSION['user_id']) || isset($_SESSION['owner_id']);
    
    // Dark mode toggle button
    $darkModeToggle = '
    <button id="darkModeToggle" class="btn btn-outline-light btn-sm me-2" title="Toggle Dark Mode">
        <i class="bi bi-moon-fill" id="darkModeIcon"></i>
    </button>';
    
    if (!$isLoggedIn) {
        // Guest navigation
        return '
        <nav class="topFixedBar">
            <div class="container d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <img src="Assets/Logo1.png" alt="HanapBahay Logo" class="logo" />
                </div>
                <div class="d-flex align-items-center gap-2">
                    ' . $darkModeToggle . '
                    <a href="index.php" class="btn btn-outline-light btn-sm">Home</a>
                    <a href="browse_listings.php" class="btn btn-outline-light btn-sm">Browse</a>
                    <a href="LoginModule.php" class="btn btn-warning btn-sm text-dark">Login</a>
                    <a href="LoginModule.php?register=1" class="btn btn-warning btn-sm text-dark">Register</a>
                </div>
            </div>
        </nav>';
    }
    
    if ($role === 'tenant') {
        // Tenant navigation
        $navItems = [
            'DashboardT.php' => 'Home',
            'rental_request.php' => 'Application Status',
            'edit_profile_tenant.php' => 'Settings'
        ];
        
        $navLinks = '';
        foreach ($navItems as $url => $label) {
            $activeClass = ($currentPage === $url) ? 'active' : '';
            $navLinks .= '<li class="nav-item">
                <a class="nav-link text-white fw-bold ' . $activeClass . '" href="' . $url . '">' . $label . '</a>
            </li>';
        }
        
        return '
        <nav class="topFixedBar d-flex justify-content-between align-items-center px-4">
            <div class="d-flex align-items-center">
                <img src="Assets/Logo1.png" alt="HanapBahay Logo" class="logo" />
                <ul class="nav gap-4 ms-3">
                    ' . $navLinks . '
                </ul>
            </div>
            <div class="d-flex align-items-center gap-2">
                ' . $darkModeToggle . '
                <a href="logout.php" class="btn btn-outline-light">Logout</a>
            </div>
        </nav>';
    }
    
        if ($role === 'owner' || $role === 'unit_owner' || isset($_SESSION['owner_id'])) {
            // Owner navigation
            $navItems = [
                'DashboardUO.php' => 'Home',
                'rental_requests_uo.php' => 'Rental Requests',
                'DashboardAddUnit.php' => 'Add Properties',
                'owner_quick_replies.php' => 'Quick Replies',
                'owner_recommendations.php' => 'Recommendations',
                'email_notifications.php' => 'Email Settings',
                'account_settings.php' => 'Account Settings'
            ];
        
        $navLinks = '';
        foreach ($navItems as $url => $label) {
            $activeClass = ($currentPage === $url) ? 'active' : '';
            $navLinks .= '<li class="nav-item">
                <a class="nav-link text-white ' . $activeClass . '" href="' . $url . '">' . $label . '</a>
            </li>';
        }
        
        return '
        <nav class="topFixedBar">
            <div class="container-fluid px-3 d-flex align-items-center justify-content-between nav-height">
                <div class="d-flex align-items-center gap-3">
                    <img src="Assets/Logo1.png" alt="HanapBahay Logo" class="logo" />
                    <ul class="nav">
                        ' . $navLinks . '
                    </ul>
                </div>
                <div class="d-flex align-items-center gap-3">
                    ' . $darkModeToggle . '
                    <a href="logout.php" class="btn btn-outline-light">Logout</a>
                </div>
            </div>
        </nav>';
    }
    
    if ($role === 'admin') {
        // Admin navigation
        $navItems = [
            'admin_listings.php' => 'Manage & Verify',
            'admin_transactions.php' => 'Transactions',
            'admin_chat_management.php' => 'Chat Management',
            'admin_price_analytics.php' => 'Price Analytics'
        ];
        
        $navLinks = '';
        foreach ($navItems as $url => $label) {
            $activeClass = ($currentPage === $url) ? 'active' : '';
            $navLinks .= '<li class="nav-item">
                <a class="nav-link text-white ' . $activeClass . '" href="' . $url . '">' . $label . '</a>
            </li>';
        }
        
        return '
        <nav class="topbar py-2">
            <div class="container-fluid d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-3">
                    <img src="Assets/Logo1.png" class="logo" alt="HanapBahay" style="height:42px;">
                    <ul class="nav">
                        ' . $navLinks . '
                    </ul>
                </div>
                <div class="d-flex align-items-center gap-3">
                    ' . $darkModeToggle . '
                    <a href="logout.php" class="btn btn-sm btn-outline-light">Logout</a>
                </div>
            </div>
        </nav>';
    }
    
    // Fallback for unknown roles
    return '
    <nav class="topFixedBar">
        <div class="container d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center">
                <img src="Assets/Logo1.png" alt="HanapBahay Logo" class="logo" />
            </div>
            <div class="d-flex align-items-center gap-2">
                ' . $darkModeToggle . '
                <a href="logout.php" class="btn btn-outline-light">Logout</a>
            </div>
        </div>
    </nav>';
}

// Dark mode is handled by darkmode.js - no need for separate script
?>
