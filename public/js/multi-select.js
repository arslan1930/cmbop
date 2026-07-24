/**
 * Click-to-toggle multi-select (no Ctrl required).
 * Writes pipe-separated values into a hidden input (category names may contain commas).
 *
 * Usage:
 *   const ms = window.initMultiSelect({
 *     wrapperId, inputId, dropdownId, optionsId, hiddenInputId, searchId,
 *     maxSelections: 7,
 *     placeholderText: 'Select categories (max 7)...'
 *   });
 *   ms.setSelectedItems(['Tech'], ['Tech']);
 */
(function (global) {
  function initMultiSelect(opts) {
    if (!global.jQuery) {
      console.error('initMultiSelect requires jQuery');
      return null;
    }
    const $ = global.jQuery;
    const {
      wrapperId,
      inputId,
      dropdownId,
      optionsId,
      hiddenInputId,
      searchId,
      maxSelections = null,
      placeholderText = 'Select options...',
    } = opts;

    let selectedItems = [];
    const wrapper = $(`#${wrapperId}`);
    const input = $(`#${inputId}`);
    const dropdown = $(`#${dropdownId}`);
    const optionsContainer = $(`#${optionsId}`);
    const hiddenInput = $(`#${hiddenInputId}`);
    const searchInput = $(`#${searchId}`);

    if (!wrapper.length || !input.length || !dropdown.length) {
      return null;
    }

    function updateDisplay() {
      input.empty();
      if (selectedItems.length === 0) {
        input.html(`<span class="multi-select-placeholder">${placeholderText}</span>`);
      } else {
        selectedItems.forEach((item) => {
          const tag = $(`
            <span class="multi-select-tag">
              ${$('<div>').text(item.label).html()}
              <span class="remove-tag" data-value="${$('<div>').text(item.value).html()}">&times;</span>
            </span>
          `);
          tag.find('.remove-tag').on('click', function (e) {
            e.stopPropagation();
            removeItem(item.value);
          });
          input.append(tag);
        });
      }
      hiddenInput.val(selectedItems.map((item) => item.value).join('|'));
      hiddenInput.trigger('change');
    }

    function addItem(value, label) {
      if (maxSelections && selectedItems.length >= maxSelections) {
        if (global.Swal) {
          global.Swal.fire({
            icon: 'warning',
            title: `Maximum ${maxSelections} selections allowed`,
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 2000,
          });
        }
        return false;
      }
      if (!selectedItems.some((item) => item.value === value)) {
        selectedItems.push({ value, label });
        updateDisplay();
        updateOptionsHighlight();
        return true;
      }
      return false;
    }

    function removeItem(value) {
      selectedItems = selectedItems.filter((item) => item.value !== value);
      updateDisplay();
      updateOptionsHighlight();
    }

    function updateOptionsHighlight() {
      optionsContainer.find('.multi-select-option').each(function () {
        const $this = $(this);
        const value = $this.data('value');
        $this.toggleClass(
          'selected',
          selectedItems.some((item) => item.value === value)
        );
      });
    }

    function filterOptions(searchTerm) {
      const term = String(searchTerm || '').toLowerCase();
      optionsContainer.find('.multi-select-option').each(function () {
        const $this = $(this);
        const text = $this.text().toLowerCase();
        $this.toggleClass('hidden', !(term === '' || text.includes(term)));
      });
    }

    input.on('click', function (e) {
      e.stopPropagation();
      $('.multi-select-dropdown').not(dropdown).removeClass('show');
      $('.single-select-dropdown').removeClass('show');
      dropdown.toggleClass('show');
      if (dropdown.hasClass('show')) {
        searchInput.focus();
        filterOptions('');
      }
    });

    dropdown.on('click', function (e) {
      e.stopPropagation();
    });

    searchInput.on('keyup', function () {
      filterOptions($(this).val());
    });

    optionsContainer.on('click', '.multi-select-option', function () {
      const $option = $(this);
      if ($option.hasClass('hidden')) return;
      const value = $option.data('value');
      const label = $option.data('label');
      if ($option.hasClass('selected')) {
        removeItem(value);
      } else {
        addItem(value, label);
      }
    });

    function setSelectedItems(values, labels) {
      selectedItems = [];
      for (let i = 0; i < values.length; i++) {
        if (values[i]) {
          selectedItems.push({ value: values[i], label: labels[i] || values[i] });
        }
      }
      updateDisplay();
      updateOptionsHighlight();
    }

    function clearSelections() {
      selectedItems = [];
      updateDisplay();
      updateOptionsHighlight();
      searchInput.val('');
      filterOptions('');
    }

    updateDisplay();

    return {
      addItem,
      removeItem,
      getSelectedItems: () => selectedItems.slice(),
      clearSelections,
      setSelectedItems,
      updateDisplay,
    };
  }

  // One document-level closer for all instances
  if (!global.__multiSelectOutsideClickBound) {
    global.__multiSelectOutsideClickBound = true;
    document.addEventListener('click', function () {
      if (!global.jQuery) return;
      global.jQuery('.multi-select-dropdown').removeClass('show');
    });
  }

  global.initMultiSelect = initMultiSelect;
})(typeof window !== 'undefined' ? window : globalThis);
