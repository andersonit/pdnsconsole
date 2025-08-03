# Theme System Implementation Details

## Overview
The PDNS Console implements a comprehensive theme system that provides both light and dark Bootstrap themes with the following features:
- Bootswatch themes integration via CDN
- User theme preference persistence in database
- Dark mode support with natural and forced dark mode options
- Theme selector modal for convenient theme switching
- Full white-labeling capabilities for commercial use

## Theme Architecture

### Theme Loading
- **CDN-Based Delivery**: All themes loaded from jsdelivr CDN for performance
- **Fallback Mechanism**: Default Bootstrap CSS included as fallback
- **CSS Variables**: Theme-compatible custom styling using Bootstrap CSS variables
- **Dynamic Loading**: Theme CSS URL generated dynamically based on user preferences

### Theme Categories
- **Light Themes**: Default Bootstrap, Cerulean, Cosmo, Flatly, Journal, Litera, etc.
- **Dark Themes**: Cyborg, Darkly, Slate, Solar, Superhero, Vapor
- **Specialty Themes**: Sketchy (hand-drawn style), Quartz (glassmorphism)

### User Experience
- **Theme Modal**: Interactive theme selection with visual previews
- **Live Preview**: Instant theme application without page reload
- **Persistent Preferences**: User theme choices saved to database
- **Dark Mode Toggle**: Separate from theme selection for additional flexibility

## Dark Mode Implementation
- **Two-Layer Approach**: 
  1. Naturally dark themes (Darkly, Cyborg, etc.)
  2. CSS-based dark mode overlay for light themes
- **Detection System**: Check if theme is in NATURALLY_DARK_THEMES array
- **Effective Dark**: Boolean flag combining natural darkness and manual dark mode toggle
- **Transition Effects**: Smooth transition between themes and dark mode states
- **System Preference Detection**: Optional detection of OS-level dark mode preference

## Theme Selector User Interface
- **Modal Dialog**: Rich interactive theme selection experience
- **Theme Grid**: Visual theme cards with preview thumbnails
- **Category Tabs**: Separate tabs for Light and Dark themes
- **Live Preview**: Apply theme without closing modal or reloading
- **Active Indicator**: Clear visual indication of current theme
- **Search**: Filter themes by name (for larger theme collections)
- **Preview Capability**: "Try" button to preview without saving

## JavaScript Implementation
- **Fetch API**: Modern Promise-based AJAX for theme API communication
- **Asynchronous Loading**: Non-blocking theme changes
- **Event Delegation**: Efficient event handling for theme selection
- **Local Storage Caching**: Optional caching for improved performance
- **Error Handling**: Graceful fallback if theme loading fails

## CSS Architecture for Theme Support
- **Theme Variables**: Using CSS variables for consistent theming
  ```css
  /* Example of theme-aware styling */
  .custom-element {
    color: var(--bs-primary);
    background-color: var(--bs-light);
    border: 1px solid var(--bs-border-color);
  }
  
  /* Dark mode override */
  body.dark-mode .custom-element {
    background-color: var(--bs-dark);
    color: var(--bs-light);
  }
  ```

- **Smooth Transitions**: CSS transitions for theme switches
  ```css
  /* Smooth theme transitions */
  body {
    transition: background-color 0.3s ease, color 0.3s ease;
  }
  
  .card, .btn, .form-control {
    transition: background-color 0.3s ease, border-color 0.3s ease, color 0.3s ease;
  }
  ```

## Theme Backend Implementation
- **Settings Class Methods**:
  - `getThemeInfo()`: Returns complete theme configuration
  - `setTheme($theme)`: Changes and persists theme selection
  - `isDarkMode()`: Checks dark mode status
  - `setDarkMode($enabled)`: Toggles dark mode
  - `isNaturallyDarkTheme($theme)`: Checks if theme is inherently dark

- **API Endpoints**:
  - GET `/api/theme.php`: Retrieve theme configuration and options
  - POST `/api/theme.php`: Update theme preferences

## Mobile Considerations
- **Responsive Design**: All theme components fully responsive
- **Touch-Friendly**: Large touch targets for theme selection
- **Bandwidth Optimization**: Minimal CSS file sizes
- **Device Preference**: Optional respect for device color scheme preference

## Future Theme Enhancements
- **Custom Theme Builder**: Allow admins to create custom themes
- **Theme Import/Export**: Share themes between installations
- **Per-User Themes**: Individual theme preferences for each user
- **Scheduled Themes**: Light/dark themes based on time of day
- **Dynamic Color Generation**: Algorithmically generated variations
