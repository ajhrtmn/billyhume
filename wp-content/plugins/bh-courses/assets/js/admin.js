(function ($) {
    $(function () {
        /* ---- course: drag-to-reorder lesson list ---- */
        var $list = $('#bhc-lesson-order-list');
        if ($list.length) {
            var dragged = null;
            $list.on('dragstart', '.bhc-order-item', function (e) { dragged = this; });
            $list.on('dragover', '.bhc-order-item', function (e) {
                e.preventDefault();
                var target = this;
                if (target !== dragged) {
                    var rect = target.getBoundingClientRect();
                    var before = (e.originalEvent.clientY - rect.top) < rect.height / 2;
                    target.parentNode.insertBefore(dragged, before ? target : target.nextSibling);
                }
            });
            $list.on('drop', function (e) { e.preventDefault(); syncOrder(); });
            function syncOrder() {
                var ids = $list.find('.bhc-order-item').map(function () { return $(this).data('id'); }).get();
                $('#bhc_lesson_order').val(ids.join(','));
            }
            syncOrder();
        }

        /* ---- lesson: multistep builder ---- */
        var $builder = $('#bhc-steps-builder');
        if (!$builder.length) return;

        var steps = [];
        try { steps = JSON.parse($builder.attr('data-steps') || '[]'); } catch (e) { steps = []; }

        function render() {
            $builder.empty();
            steps.forEach(function (step, i) { $builder.append(renderStep(step, i)); });
            $builder.append(
                '<p class="bhc-add-row">' +
                '<button type="button" class="button bhc-add-step" data-type="text">+ Text step</button> ' +
                '<button type="button" class="button bhc-add-step" data-type="image">+ Image step</button> ' +
                '<button type="button" class="button bhc-add-step" data-type="video">+ Video step</button> ' +
                '<button type="button" class="button bhc-add-step" data-type="quiz">+ Quiz step</button>' +
                '</p>'
            );
            sync();
        }

        function renderStep(step, i) {
            var $row = $('<div class="bhc-step-editor" data-index="' + i + '"></div>');
            var $head = $('<div class="bhc-step-editor-head"><strong>Step ' + (i + 1) + ': ' + step.type + '</strong> ' +
                '<button type="button" class="button-link bhc-move-up">&uarr;</button> ' +
                '<button type="button" class="button-link bhc-move-down">&darr;</button> ' +
                '<button type="button" class="button-link bhc-remove-step" style="color:#b32d2e;">Remove</button></div>');
            $row.append($head);

            if (step.type === 'text') {
                $row.append('<textarea class="widefat bhc-field-content" rows="5" placeholder="Lesson text (HTML allowed)…">' + (step.content || '') + '</textarea>');
            } else if (step.type === 'image') {
                var ids = step.attachment_ids || [];
                $row.append(
                    '<div class="bhc-image-preview">' + ids.map(function (id) { return '<span class="bhc-image-thumb" data-id="' + id + '">#' + id + ' <a href="#" class="bhc-remove-image">&times;</a></span>'; }).join(' ') + '</div>' +
                    '<button type="button" class="button bhc-select-image">Select image(s)</button>' +
                    '<input type="text" class="widefat bhc-field-caption" placeholder="Caption (optional)" value="' + (step.caption || '') + '" style="margin-top:6px;">'
                );
            } else if (step.type === 'video') {
                var source = step.source || 'upload';
                $row.append(
                    '<p><label><input type="radio" class="bhc-field-video-source" name="bhc-video-source-' + i + '" value="upload"' + (source === 'upload' ? ' checked' : '') + '> Upload a video file</label> ' +
                    '<label style="margin-left:16px;"><input type="radio" class="bhc-field-video-source" name="bhc-video-source-' + i + '" value="url"' + (source === 'url' ? ' checked' : '') + '> Link to an external URL (Cloudflare Stream, Bunny, YouTube/Vimeo embed, etc.)</label></p>'
                );
                var $uploadRow = $('<div class="bhc-video-upload-row"' + (source === 'upload' ? '' : ' style="display:none;"') + '>' +
                    '<span class="bhc-video-preview">' + (step.attachment_id ? 'Attachment #' + step.attachment_id : 'No file selected') + '</span> ' +
                    '<button type="button" class="button bhc-select-video">Select video file</button>' +
                    '</div>');
                var $urlRow = $('<div class="bhc-video-url-row"' + (source === 'url' ? '' : ' style="display:none;"') + '>' +
                    '<input type="url" class="widefat bhc-field-video-url" placeholder="https://…" value="' + (step.video_url || '') + '">' +
                    '</div>');
                $row.append($uploadRow).append($urlRow);
                $row.append('<input type="text" class="widefat bhc-field-caption" placeholder="Caption (optional)" value="' + (step.caption || '') + '" style="margin-top:6px;">');
            } else if (step.type === 'quiz') {
                var $q = $('<div class="bhc-quiz-editor"></div>');
                (step.questions || []).forEach(function (q, qi) { $q.append(renderQuestion(q, qi)); });
                $q.append('<button type="button" class="button bhc-add-question">+ Question</button>');
                $row.append($q);
                $row.append(
                    '<p><label>Passing score % <input type="number" class="bhc-field-passing" min="0" max="100" value="' + (step.passing_score != null ? step.passing_score : 70) + '" style="width:70px;"></label> ' +
                    '<label style="margin-left:16px;">Max attempts (0 = unlimited) <input type="number" class="bhc-field-max-attempts" min="0" value="' + (step.max_attempts != null ? step.max_attempts : 0) + '" style="width:70px;"></label></p>'
                );
            }
            return $row;
        }

        function renderQuestion(q, qi) {
            var choices = q.choices || ['', ''];
            var html = '<div class="bhc-question-editor" data-qindex="' + qi + '">' +
                '<input type="text" class="widefat bhc-field-question" placeholder="Question" value="' + (q.question || '') + '">' +
                '<div class="bhc-choices">' +
                choices.map(function (c, ci) {
                    return '<div class="bhc-choice-row"><input type="radio" class="bhc-field-correct" ' + (ci === (q.correct_index || 0) ? 'checked' : '') + '> ' +
                        '<input type="text" class="bhc-field-choice" placeholder="Choice" value="' + c + '"> ' +
                        '<button type="button" class="button-link bhc-remove-choice" style="color:#b32d2e;">&times;</button></div>';
                }).join('') +
                '</div>' +
                '<button type="button" class="button-link bhc-add-choice">+ choice</button> ' +
                '<button type="button" class="button-link bhc-remove-question" style="color:#b32d2e;">Remove question</button>' +
                '</div>';
            return $(html);
        }

        // Note: `steps` (in-memory) is the single source of truth: every
        // handler below mutates it directly and calls render()/sync() —
        // the DOM is pure presentation, never re-parsed back into state.
        function sync() {
            $('#bhc_steps_json').val(JSON.stringify(steps));
        }

        $builder.on('click', '.bhc-add-step', function () {
            var type = $(this).data('type');
            if (type === 'text') steps.push({ type: 'text', content: '' });
            else if (type === 'image') steps.push({ type: 'image', attachment_ids: [], caption: '' });
            else if (type === 'video') steps.push({ type: 'video', source: 'upload', attachment_id: 0, video_url: '', caption: '' });
            else steps.push({ type: 'quiz', passing_score: 70, questions: [{ question: '', choices: ['', ''], correct_index: 0 }] });
            render();
        });

        $builder.on('click', '.bhc-remove-step', function () {
            steps.splice($(this).closest('.bhc-step-editor').data('index'), 1);
            render();
        });
        $builder.on('click', '.bhc-move-up', function () {
            var i = $(this).closest('.bhc-step-editor').data('index');
            if (i > 0) { var t = steps[i]; steps[i] = steps[i - 1]; steps[i - 1] = t; render(); }
        });
        $builder.on('click', '.bhc-move-down', function () {
            var i = $(this).closest('.bhc-step-editor').data('index');
            if (i < steps.length - 1) { var t = steps[i]; steps[i] = steps[i + 1]; steps[i + 1] = t; render(); }
        });

        $builder.on('input', '.bhc-field-content', function () {
            steps[$(this).closest('.bhc-step-editor').data('index')].content = $(this).val(); sync();
        });
        $builder.on('input', '.bhc-field-caption', function () {
            steps[$(this).closest('.bhc-step-editor').data('index')].caption = $(this).val(); sync();
        });
        $builder.on('input', '.bhc-field-passing', function () {
            steps[$(this).closest('.bhc-step-editor').data('index')].passing_score = parseInt($(this).val(), 10) || 0; sync();
        });
        $builder.on('input', '.bhc-field-max-attempts', function () {
            steps[$(this).closest('.bhc-step-editor').data('index')].max_attempts = parseInt($(this).val(), 10) || 0; sync();
        });

        $builder.on('change', '.bhc-field-video-source', function () {
            var stepIndex = $(this).closest('.bhc-step-editor').data('index');
            steps[stepIndex].source = $(this).val();
            render();
        });
        $builder.on('click', '.bhc-select-video', function () {
            var stepIndex = $(this).closest('.bhc-step-editor').data('index');
            var frame = wp.media({ title: 'Select a video file', library: { type: 'video' }, multiple: false });
            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                steps[stepIndex].attachment_id = attachment.id;
                render();
            });
            frame.open();
        });
        $builder.on('input', '.bhc-field-video-url', function () {
            steps[$(this).closest('.bhc-step-editor').data('index')].video_url = $(this).val(); sync();
        });

        $builder.on('click', '.bhc-select-image', function () {
            var stepIndex = $(this).closest('.bhc-step-editor').data('index');
            var frame = wp.media({ title: 'Select image(s)', multiple: true });
            frame.on('select', function () {
                var selection = frame.state().get('selection').map(function (a) { return a.id; });
                steps[stepIndex].attachment_ids = (steps[stepIndex].attachment_ids || []).concat(selection);
                render();
            });
            frame.open();
        });
        $builder.on('click', '.bhc-remove-image', function (e) {
            e.preventDefault();
            var stepIndex = $(this).closest('.bhc-step-editor').data('index');
            var id = parseInt($(this).closest('.bhc-image-thumb').data('id'), 10);
            steps[stepIndex].attachment_ids = (steps[stepIndex].attachment_ids || []).filter(function (i) { return i !== id; });
            render();
        });

        $builder.on('click', '.bhc-add-question', function () {
            var stepIndex = $(this).closest('.bhc-step-editor').data('index');
            steps[stepIndex].questions.push({ question: '', choices: ['', ''], correct_index: 0 });
            render();
        });
        $builder.on('click', '.bhc-remove-question', function () {
            var stepIndex = $(this).closest('.bhc-step-editor').data('index');
            var qIndex = $(this).closest('.bhc-question-editor').data('qindex');
            steps[stepIndex].questions.splice(qIndex, 1);
            render();
        });
        $builder.on('click', '.bhc-add-choice', function () {
            var stepIndex = $(this).closest('.bhc-step-editor').data('index');
            var qIndex = $(this).closest('.bhc-question-editor').data('qindex');
            steps[stepIndex].questions[qIndex].choices.push('');
            render();
        });
        $builder.on('click', '.bhc-remove-choice', function () {
            var $choiceRow = $(this).closest('.bhc-choice-row');
            var choiceIndex = $choiceRow.index();
            var stepIndex = $(this).closest('.bhc-step-editor').data('index');
            var qIndex = $(this).closest('.bhc-question-editor').data('qindex');
            var q = steps[stepIndex].questions[qIndex];
            q.choices.splice(choiceIndex, 1);
            if (q.correct_index >= q.choices.length) q.correct_index = 0;
            render();
        });
        $builder.on('input', '.bhc-field-question', function () {
            var stepIndex = $(this).closest('.bhc-step-editor').data('index');
            var qIndex = $(this).closest('.bhc-question-editor').data('qindex');
            steps[stepIndex].questions[qIndex].question = $(this).val(); sync();
        });
        $builder.on('input', '.bhc-field-choice', function () {
            var $choiceRow = $(this).closest('.bhc-choice-row');
            var choiceIndex = $choiceRow.index();
            var stepIndex = $(this).closest('.bhc-step-editor').data('index');
            var qIndex = $(this).closest('.bhc-question-editor').data('qindex');
            steps[stepIndex].questions[qIndex].choices[choiceIndex] = $(this).val(); sync();
        });
        $builder.on('change', '.bhc-field-correct', function () {
            var $choiceRow = $(this).closest('.bhc-choice-row');
            var choiceIndex = $choiceRow.index();
            var stepIndex = $(this).closest('.bhc-step-editor').data('index');
            var qIndex = $(this).closest('.bhc-question-editor').data('qindex');
            steps[stepIndex].questions[qIndex].correct_index = choiceIndex; sync();
        });

        render();

        // Steps are stored in memory and only ever written to the hidden
        // field via sync() — make sure the very last edit before submit
        // (e.g. a still-focused text field that hasn't blurred) is
        // captured too.
        $(document).on('submit', 'form#post', function () { sync(); });
    });
})(jQuery);
