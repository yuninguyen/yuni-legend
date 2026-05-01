<style>
    /* 
      Systematic Debugging Fix:
      When ANY Filament modal is open (Alpine sets .fi-modal-open), 
      force teleported dropdown panels AND the Table Header/Toolbar to hide or lose z-index.
    */
    body:has(.fi-modal.fi-modal-open) .fi-dropdown-panel {
        display: none !important;
        opacity: 0 !important;
        pointer-events: none !important;
    }
    
    body:has(.fi-modal.fi-modal-open) .fi-ta-header-toolbar,
    body:has(.fi-modal.fi-modal-open) .fi-ta-selection-indicator {
        z-index: 0 !important;
        opacity: 0 !important;
        pointer-events: none !important;
    }
</style>
