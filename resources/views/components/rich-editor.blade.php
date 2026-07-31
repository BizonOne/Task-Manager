@props([
    // Name of the form field. The component keeps a hidden input in sync, so
    // the surrounding form posts exactly as it did with a plain <textarea>.
    'name' => null,
    'value' => null,
    'placeholder' => 'Write something…',
    'minHeight' => 150,
    // 'full' for descriptions and notes, 'compact' for comment boxes.
    'toolbar' => 'full',
])

@once
    @push('styles')
        <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
        <style>
            /* ── Shared rich-text editor ───────────────────────────── */
            .rt-editor .ql-toolbar.ql-snow {
                border: 1px solid #e5e7eb;
                border-bottom: none;
                border-radius: 8px 8px 0 0;
                background: #fafbfc;
                padding: 5px 8px;
                font-family: inherit;
            }

            .rt-editor .ql-container.ql-snow {
                border: 1px solid #e5e7eb;
                border-radius: 0 0 8px 8px;
                font-family: inherit;
                font-size: 13px;
                background: #fff;
            }

            .rt-editor .ql-editor {
                padding: 10px 12px;
                line-height: 1.55;
            }

            .rt-editor .ql-editor.ql-blank::before {
                font-style: normal;
                color: #9ca3af;
                left: 12px;
                right: 12px;
            }

            .rt-editor.is-invalid .ql-toolbar.ql-snow,
            .rt-editor.is-invalid .ql-container.ql-snow {
                border-color: #dc3545;
            }

            /* The editor only appears once Quill has upgraded it, so a slow
               script never leaves an empty box the person can type into and
               lose. */
            .rt-editor:not(.rt-ready) .rt-surface {
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                background: #fff;
            }
        </style>
    @endpush

    @push('scripts')
        <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
        <script>
            /* One bootstrap for every rich-text field on the page. Each editor
               writes back into its hidden input on every change, so a form can
               be submitted normally, through Ajax, or by a script that never
               fires a submit event. */
            window.RichEditor = (function () {
                const TOOLBARS = {
                    full: [
                        [{ header: [1, 2, 3, false] }],
                        ['bold', 'italic', 'underline', 'strike'],
                        [{ list: 'ordered' }, { list: 'bullet' }],
                        ['blockquote', 'code-block'],
                        ['link'],
                        ['clean'],
                    ],
                    compact: [
                        ['bold', 'italic', 'underline'],
                        [{ list: 'ordered' }, { list: 'bullet' }],
                        ['link', 'code-block'],
                        ['clean'],
                    ],
                };

                function isBlank(quill) {
                    return quill.getText().trim() === ''
                        && quill.root.querySelector('img, hr') === null;
                }

                function mount(el) {
                    if (el.__quill) return el.__quill;

                    const input = el.querySelector('.rt-input');
                    const quill = new Quill(el.querySelector('.rt-surface'), {
                        theme: 'snow',
                        placeholder: el.dataset.placeholder || '',
                        modules: { toolbar: TOOLBARS[el.dataset.toolbar] || TOOLBARS.full },
                    });

                    if (input && input.value) {
                        // Assigning the stored HTML rather than pasting it keeps
                        // the markup we sanitised on the way in.
                        quill.clipboard.dangerouslyPasteHTML(input.value, 'silent');
                    }

                    const sync = () => {
                        if (!input) return;
                        input.value = isBlank(quill) ? '' : quill.root.innerHTML;
                    };
                    quill.on('text-change', sync);
                    sync();

                    el.__quill = quill;
                    el.classList.add('rt-ready');
                    el.dispatchEvent(new CustomEvent('rich-editor:ready', {
                        detail: { quill }, bubbles: true,
                    }));

                    return quill;
                }

                function mountAll(root) {
                    (root || document).querySelectorAll('[data-rich-editor]').forEach(mount);
                }

                document.addEventListener('DOMContentLoaded', () => mountAll());

                return { mount, mountAll, isBlank };
            })();
        </script>
    @endpush
@endonce

<div class="rt-editor {{ $errors->has($name) ? 'is-invalid' : '' }}"
     data-rich-editor
     data-toolbar="{{ $toolbar }}"
     data-placeholder="{{ $placeholder }}"
     style="--rt-min-height: {{ $minHeight }}px"
     {{ $attributes }}>
    <div class="rt-surface" style="min-height: {{ $minHeight }}px"></div>
    @if($name)
        <textarea class="rt-input" name="{{ $name }}" hidden>{{ $value }}</textarea>
    @endif
</div>
