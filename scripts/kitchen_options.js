// Function to toggle kitchen type and access fields based on kitchen availability
function toggleKitchenOptions() {
    const kitchenSelect = document.getElementById('kitchenInput');
    const kitchenTypeContainer = document.getElementById('kitchenTypeContainer');
    const kitchenAccessContainer = document.getElementById('kitchenAccessContainer');
    
    if (!kitchenSelect || !kitchenTypeContainer || !kitchenAccessContainer) return;
    
    const hasKitchen = kitchenSelect.value === 'Yes';
    
    kitchenTypeContainer.style.display = hasKitchen ? 'block' : 'none';
    kitchenAccessContainer.style.display = hasKitchen ? 'block' : 'none';
    
    if (!hasKitchen) {
        // Reset the values when kitchen is not available
        const kitchenTypeSelect = document.getElementById('kitchenTypeInput');
        const kitchenAccessSelect = document.getElementById('kitchenAccessInput');
        
        if (kitchenTypeSelect) kitchenTypeSelect.value = 'none';
        if (kitchenAccessSelect) kitchenAccessSelect.value = 'private';
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    const kitchenSelect = document.getElementById('kitchenInput');
    if (kitchenSelect) {
        toggleKitchenOptions(); // Set initial state
        kitchenSelect.addEventListener('change', toggleKitchenOptions);
    }
});