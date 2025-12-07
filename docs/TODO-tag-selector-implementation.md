# TODO: WordPress Native Tag Selector Implementation

## Reference Image Analysis
Based on the provided image, we need to implement a tag selector that matches WordPress native behavior with:
- Bordered input container with white background
- Tag pills with gray background and X buttons
- Inline input field that allows typing
- Text to explain (Separate with commas or enter key)

## Current Issues to Fix

### 1. HTML Structure Issues
- [ ] **Fix tag display structure** - Tags should appear as visual pills inside the input container
- [ ] **Combine input and tags in single container** - Currently tags appear outside the input area
- [ ] **Add, remove or modify the existant WordPress classes** for native styling, as we need to create the css code that does it.
- [ ] **Remove separate input and checklist divs** - Should be unified container

### 2. CSS Implementation
- [ ] **Create bordered input container** - White background, gray border like in image
- [ ] **Style tag pills** - Gray background, rounded corners, inline display
- [ ] **Position X buttons correctly** - Inside each tag pill, right-aligned
- [ ] **Style input field** - Borderless, transparent, grows with content
- [ ] **Add hover/focus states** - Blue border focus, red X button hover

### 3. JavaScript Logic Required
- [ ] **Comma detection** - Convert text to tag when user types comma
- [ ] **Enter key detection** - Convert text to tag when user presses enter
- [ ] **X button functionality** - Remove tag when clicked
- [ ] **Input field management** - Clear field after tag creation, focus management
- [ ] **Duplicate prevention** - Don't allow duplicate tags

### 4. Example of HTML Structure Needed

```html
<!-- Target Structure -->
<div class="keyword-input-wrapper">
    <div class="keyword-input-container">
        <!-- Existing tags as pills -->
        <span class="keyword-tag">
            <span class="keyword-text">2013</span>
            <button class="keyword-remove" type="button">×</button>
        </span>
        <span class="keyword-tag">
            <span class="keyword-text">episódios</span>
            <button class="keyword-remove" type="button">×</button>
        </span>
        <!-- Input field for new tags -->
        <input type="text" class="keyword-input" placeholder="" />
    </div>
    <p class="howto">Separe com vírgulas ou a tecla enter.</p>
</div>
```

### 5. CSS Requirements as per ### 2. CSS Implementation

```css
/* Container with border like WordPress */
.keyword-input-container {
    background: #fff;
    border: 1px solid #8c8f94;
    border-radius: 4px;
    padding: 4px 6px;
    min-height: 30px;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px;
    cursor: text;
}

/* Focus state */
.keyword-input-container:focus-within {
    border-color: #2271b1;
    box-shadow: 0 0 0 1px #2271b1;
}

/* Tag pills */
.keyword-tag {
    background: #f0f0f1;
    border: 1px solid #c3c4c7;
    border-radius: 3px;
    padding: 2px 4px 2px 8px;
    display: inline-flex;
    align-items: center;
    font-size: 13px;
    white-space: nowrap;
}

/* X buttons */
.keyword-remove {
    background: none;
    border: none;
    color: #666;
    cursor: pointer;
    font-size: 12px;
    margin-left: 4px;
    padding: 0;
    width: 14px;
    height: 14px;
}

.keyword-remove:hover {
    background: #d63638;
    color: #fff;
    border-radius: 2px;
}

/* Input field */
.keyword-input {
    border: none;
    outline: none;
    background: transparent;
    flex: 1;
    min-width: 120px;
    padding: 2px;
    font-size: 13px;
}
```

### 6. JavaScript Implementation Steps

1. **Setup Event Listeners**
   - [ ] Comma keypress detection
   - [ ] Enter keypress detection
   - [ ] X button click handlers
   - [ ] Input field focus management

2. **Tag Creation Logic**
   - [ ] Extract text from input field
   - [ ] Validate tag (not empty, not duplicate)
   - [ ] Create tag HTML element
   - [ ] Insert before input field
   - [ ] Clear input field
   - [ ] Update hidden data for save

3. **Tag Removal Logic**
   - [ ] Remove tag element from DOM
   - [ ] Update hidden data for save
   - [ ] Focus back to input field

4. **Save Integration**
   - [ ] Collect all tags from both containers
   - [ ] Send via AJAX to save handler
   - [ ] Handle success/error feedback

### 7. Integration with Existing Code

- [ ] **Update PHP rendering** - Use new HTML structure
- [ ] **Update AJAX handlers** - Accept new tag data format
- [ ] **Add JavaScript file** - Create dedicated JS for tag management
- [ ] **Enqueue scripts properly** - Ensure JS loads on admin page
- [ ] **Localize strings** - Make "Separe com vírgulas..." translatable

### 8. Testing Requirements

- [ ] **Visual testing** - Matches reference image exactly
- [ ] **Functional testing** - Comma and enter key work
- [ ] **Removal testing** - X buttons work correctly
- [ ] **Save testing** - Data persists correctly
- [ ] **Responsive testing** - Works on mobile devices

### 9. Files to Modify

1. **includes/class-autotag-admin-html.php** - Update HTML structure
2. **admin/css/admin.css** - Add CSS styles
3. **admin/js/tag-selector.js** - Create new JavaScript file
4. **includes/class-autotag-admin.php** - Update script enqueuing and AJAX handlers

### 10. Priority Order

1. **HIGH**: Generate a correct HTML structure
2. **HIGH**: Add CSS for visual appearance
3. **MEDIUM**: Implement JavaScript functionality
4. **MEDIUM**: Update AJAX save handlers
5. **LOW**: Add responsive improvements
6. **LOW**: Add accessibility enhancements

---

## Success Criteria

✅ Visual appearance matches reference image exactly
✅ Typing comma creates tag immediately
✅ Pressing enter creates tag immediately
✅ X buttons remove tags
✅ Tags save to database correctly
✅ Two separate containers (Tags and Categories)
✅ No duplicate tags allowed
✅ Proper focus management