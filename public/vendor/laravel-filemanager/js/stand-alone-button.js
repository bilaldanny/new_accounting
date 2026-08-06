window.lfm = function(id, type = 'file', options = {}) {
  const buttons = document.querySelectorAll('.' + id);
  if (!buttons.length) return;

  buttons.forEach(button => {
    button.addEventListener('click', function (e) {
      e.preventDefault();

      const route_prefix = options.prefix || '/laravel-filemanager';
      const multiple = options.multiple || false;
      const base_url = options.base_url || window.location.origin;
      const inputId = button.getAttribute('data-input');
      const previewId = button.getAttribute('data-preview');

      const target_input = document.getElementById(inputId);
      const target_preview = document.getElementById(previewId);

      if (!target_input) {
        console.warn(`lfm: input element with id="${inputId}" not found`);
        return;
      }

      const input_value = target_input.value ? target_input.value.split(',') : [];

      window.open(
        `${route_prefix}?type=${type}${multiple ? '&multiple=true' : ''}`,
        'FileManager',
        'width=900,height=600'
      );

      window.SetUrl = function (items) {
        // Map URLs and remove base_url
        console.log(base_url)
        const file_path = items.map(item => {
          let url = item.url;
          if (base_url) {
            url = url.replace(new RegExp(`^${base_url}`), '');
          }
          if (input_value.includes(url)) {
            return null; // skip duplicates
          }
          return url;
        }).filter(Boolean);

        // Merge values if multiple
        let merged_values = '';
        if (multiple) {
          if (input_value[input_value.length - 1] !== '') {
            merged_values = Array.from(new Set([...input_value, ...file_path])).join(',');
          } else {
            merged_values = file_path.join(',');
          }
        } else {
          merged_values = file_path.join(',');
        }

        // ✅ Set the value of input field
        target_input.value = merged_values;
        target_input.dispatchEvent(new Event('input', { bubbles: true }));

        // ✅ Update preview
        if (target_preview) {
          target_preview.innerHTML = ''; // clear old previews

          if (multiple) {
            const itemsArr = merged_values.split(',');
            itemsArr.forEach(itemUrl => {
              const image_url = itemUrl;
              const wrapper = document.createElement('div');
              wrapper.className = 'image-item';
              wrapper.innerHTML = `
                <img src="${image_url}" />
                <button class="remove-btn file-remove" data-url="${image_url.replace(new RegExp(`^${base_url}`), '')}">✖</button>
              `;
              target_preview.appendChild(wrapper);
            });
          } else {
            items.forEach(item => {
              const image_url = item.thumb_url ?? item.url;
              const img = document.createElement('img');
              img.style.height = '5rem';
              img.src = image_url;
              target_preview.appendChild(img);
            });
          }

          // Trigger change event
          target_preview.dispatchEvent(new Event('change', { bubbles: true }));
        }
      };
    });
  });
};
