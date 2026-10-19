(function ($) {
	'use strict';

	if (typeof wcs3dmAdmin === 'undefined') {
		return;
	}

	var state = {
		jobId: null,
		pollTimer: null,
		xhr: null
	};

	function post(action, data) {
		data = data || {};
		data.action = action;
		data.nonce = wcs3dmAdmin.nonce;
		return $.ajax({
			url: wcs3dmAdmin.ajaxUrl,
			method: 'POST',
			data: data
		});
	}

	function statusLabel(status) {
		if (status === 'passed') return 'Passed';
		if (status === 'failed') return 'Failed';
		if (status === 'not_tested') return 'Not tested';
		return status || '';
	}

	function renderTests($el, tests, summaryHtml) {
		tests = tests || [];
		var counts = { passed: 0, failed: 0, not_tested: 0 };
		tests.forEach(function (t) {
			if (counts[t.status] != null) counts[t.status] += 1;
		});
		var html = summaryHtml || '';
		html += '<div class="wcs3dm-test-summary">' +
			'<span class="wcs3dm-status-passed">' + counts.passed + ' passed</span>' +
			'<span class="wcs3dm-status-failed">' + counts.failed + ' failed</span>' +
			'<span class="wcs3dm-status-not_tested">' + counts.not_tested + ' not tested</span>' +
			'</div>';
		html += '<ul class="wcs3dm-test-list">';
		tests.forEach(function (t) {
			html += '<li class="wcs3dm-test-' + escapeHtml(t.status) + '">' +
				'<span class="wcs3dm-test-badge wcs3dm-status-' + escapeHtml(t.status) + '">' + escapeHtml(statusLabel(t.status)) + '</span>' +
				'<div><strong>' + escapeHtml(t.label) + '</strong><p>' + escapeHtml(t.message) + '</p></div></li>';
		});
		html += '</ul>';
		$el.html(html);
	}

	function escapeHtml(str) {
		return String(str == null ? '' : str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function formatBytes(n) {
		n = Number(n) || 0;
		if (n < 1024) return n + ' B';
		if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
		if (n < 1073741824) return (n / 1048576).toFixed(1) + ' MB';
		return (n / 1073741824).toFixed(2) + ' GB';
	}

	$(document).on('click', '#wcs3dm-test-connection', function (e) {
		e.preventDefault();
		var $out = $('#wcs3dm-connection-results').text('Testing…');
		post('wcs3dm_test_connection').done(function (resp) {
			if (!resp.success) {
				$out.text((resp.data && resp.data.message) || 'Failed');
				return;
			}
			var html = '';
			if (resp.data.leftover_key) {
				html += '<p class="notice notice-warning inline">A test file is still in the bucket: <code>' + escapeHtml(resp.data.leftover_key) + '</code></p>';
			}
			renderTests($out, resp.data.tests, html);
		}).fail(function () {
			$out.text('Request failed');
		});
	});

	$(document).on('click', '#wcs3dm-run-diagnostics', function (e) {
		e.preventDefault();
		var $out = $('#wcs3dm-diagnostics-results').text('Running…');
		post('wcs3dm_run_diagnostics').done(function (resp) {
			if (!resp.success) {
				$out.text('Failed');
				return;
			}
			renderTests($out, resp.data.tests);
		});
	});

	$(document).on('click', '#wcs3dm-refresh-overview', function (e) {
		e.preventDefault();
		var $m = $('#wcs3dm-overview-metrics').text('Loading…');
		post('wcs3dm_overview').done(function (resp) {
			if (!resp.success) {
				$m.text('Failed to load metrics');
				return;
			}
			var c = resp.data.counts || {};
			var s = resp.data.stats || {};
			var job = resp.data.job;
			var html = '';
			[
				['Total downloadable files', c.total_files],
				['Stored locally', c.local],
				['Stored on S3', c.s3],
				['Missing', c.missing],
				['Website link, file missing', c.unsupported],
				['Recoverable disk (est.)', formatBytes(c.eligible_bytes)],
				['Bytes transferred', formatBytes(s.bytes_transferred)],
				['Migrated files', s.files_migrated || 0],
				['Failed migrations', s.files_failed || 0]
			].forEach(function (row) {
				html += '<div class="wcs3dm-metric"><span>' + escapeHtml(row[0]) + '</span><strong>' + escapeHtml(row[1]) + '</strong></div>';
			});
			if (job) {
				html += '<div class="wcs3dm-metric"><span>Background job</span><strong>' + escapeHtml(job.status) + '</strong><div>' + escapeHtml(job.id) + '</div></div>';
			}
			$m.html(html);
		});
	});

	function browse(token) {
		var $out = $('#wcs3dm-browse-results').text('Loading…');
		post('wcs3dm_list_objects', {
			prefix: $('#wcs3dm-browse-prefix').val(),
			search: $('#wcs3dm-browse-search').val(),
			token: token || ''
		}).done(function (resp) {
			if (!resp.success || !resp.data.ok) {
				$out.text((resp.data && resp.data.message) || 'List failed');
				return;
			}
			var html = '<table class="wcs3dm-browser-table"><thead><tr><th>Key</th><th>Size</th><th>Modified</th><th></th></tr></thead><tbody>';
			(resp.data.prefixes || []).forEach(function (p) {
				html += '<tr><td colspan="3"><a href="#" class="wcs3dm-prefix-link" data-prefix="' + escapeHtml(p) + '">📁 ' + escapeHtml(p) + '</a></td><td></td></tr>';
			});
			(resp.data.objects || []).forEach(function (o) {
				html += '<tr><td><code>' + escapeHtml(o.key) + '</code></td><td>' + formatBytes(o.size) + '</td><td>' + escapeHtml(o.last_modified) + '</td>';
				html += '<td><button type="button" class="button button-small wcs3dm-pick-object" data-key="' + escapeHtml(o.key) + '">Select</button></td></tr>';
			});
			html += '</tbody></table>';
			if (resp.data.next_token) {
				html += '<p><button type="button" class="button" id="wcs3dm-browse-next" data-token="' + escapeHtml(resp.data.next_token) + '">Next page</button></p>';
			}
			$out.html(html);
		});
	}

	$(document).on('click', '#wcs3dm-browse-refresh', function (e) {
		e.preventDefault();
		browse();
	});
	$(document).on('click', '#wcs3dm-browse-next', function (e) {
		e.preventDefault();
		browse($(this).data('token'));
	});
	$(document).on('click', '.wcs3dm-prefix-link', function (e) {
		e.preventDefault();
		$('#wcs3dm-browse-prefix').val($(this).data('prefix'));
		browse();
	});

	function uploadFile(file, productId, $progress, $result, onSuccess) {
		var fd = new FormData();
		fd.append('action', 'wcs3dm_upload_file');
		fd.append('nonce', wcs3dmAdmin.nonce);
		fd.append('file', file);
		if (productId) fd.append('product_id', productId);
		if ($progress) {
			$progress.prop('hidden', false).find('.wcs3dm-progress-bar').css('width', '10%');
		}
		if ($result) $result.text(wcs3dmAdmin.i18n.uploading);
		if (state.xhr) {
			try { state.xhr.abort(); } catch (err) {}
		}
		state.xhr = $.ajax({
			url: wcs3dmAdmin.ajaxUrl,
			method: 'POST',
			data: fd,
			processData: false,
			contentType: false,
			xhr: function () {
				var xhr = $.ajaxSettings.xhr();
				if (xhr.upload && $progress) {
					xhr.upload.addEventListener('progress', function (ev) {
						if (ev.lengthComputable) {
							var pct = Math.round((ev.loaded / ev.total) * 100);
							$progress.find('.wcs3dm-progress-bar').css('width', pct + '%');
						}
					});
				}
				return xhr;
			}
		}).done(function (resp) {
			if ($progress) $progress.find('.wcs3dm-progress-bar').css('width', '100%');
			if (!resp.success) {
				if ($result) $result.text((resp.data && resp.data.message) || wcs3dmAdmin.i18n.error);
				return;
			}
			if ($result) $result.text(JSON.stringify(resp.data, null, 2));
			if (onSuccess) onSuccess(resp.data);
		}).fail(function () {
			if ($result) $result.text(wcs3dmAdmin.i18n.error);
		});
	}

	$(document).on('click', '#wcs3dm-direct-upload', function (e) {
		e.preventDefault();
		var input = document.getElementById('wcs3dm-direct-upload-input');
		if (!input || !input.files || !input.files[0]) {
			alert('Choose a file first');
			return;
		}
		uploadFile(input.files[0], 0, $('#wcs3dm-upload-progress'), $('#wcs3dm-upload-result'));
	});

	function fillDownloadRow($row, data) {
		var $name = $row.find('input[name^="_wc_file_names"], input.file_name, input[name*="[name]"]');
		var $file = $row.find('input[name^="_wc_file_urls"], input.file_url, input[name*="[file]"]');
		if ($name.length) $name.val(data.name || data.key.split('/').pop());
		if ($file.length) $file.val(data.reference);
	}

	$(document).on('click', '.wcs3dm-upload-to-s3', function (e) {
		e.preventDefault();
		var $wrap = $(this).closest('.wcs3dm-product-controls');
		var $input = $wrap.find('.wcs3dm-file-input');
		$input.off('change.wcs3dm').on('change.wcs3dm', function () {
			if (!this.files || !this.files[0]) return;
			var productId = $wrap.data('product-id') || 0;
			uploadFile(this.files[0], productId, null, null, function (data) {
				var $row = $wrap.closest('.woocommerce_variable_attributes, #woocommerce-product-data, .downloadable_files').find('tbody tr').last();
				if (!$row.length) $row = $('.downloadable_files tbody tr').last();
				fillDownloadRow($row, data);
				alert('Uploaded. Reference inserted into the nearest download row. Save the product.');
			});
		});
		$input.trigger('click');
	});

	$(document).on('click', '.wcs3dm-browse-s3', function (e) {
		e.preventDefault();
		var $wrap = $(this).closest('.wcs3dm-product-controls');
		openPicker(function (data) {
			var $row = $('.downloadable_files tbody tr').last();
			fillDownloadRow($row, data);
			alert('Selected. Save the product to persist the S3 reference.');
		});
	});

	function openPicker(cb) {
		$('#wcs3dm-s3-modal').remove();
		var $modal = $('<div id="wcs3dm-s3-modal"><div class="wcs3dm-modal-inner"><h2>Select S3 object</h2><div id="wcs3dm-modal-browser"></div><p><button type="button" class="button" id="wcs3dm-modal-close">Close</button></p></div></div>');
		$('body').append($modal);
		$('#wcs3dm-modal-close').on('click', function () { $modal.remove(); });
		function load(prefix) {
			post('wcs3dm_list_objects', { prefix: prefix || '' }).done(function (resp) {
				if (!resp.success || !resp.data.ok) {
					$('#wcs3dm-modal-browser').text((resp.data && resp.data.message) || 'Failed');
					return;
				}
				var html = '<ul>';
				(resp.data.prefixes || []).forEach(function (p) {
					html += '<li><a href="#" class="wcs3dm-modal-prefix" data-prefix="' + escapeHtml(p) + '">' + escapeHtml(p) + '</a></li>';
				});
				(resp.data.objects || []).forEach(function (o) {
					html += '<li><button type="button" class="button-link wcs3dm-modal-pick" data-key="' + escapeHtml(o.key) + '">' + escapeHtml(o.key) + '</button> (' + formatBytes(o.size) + ')</li>';
				});
				html += '</ul>';
				$('#wcs3dm-modal-browser').html(html);
			});
		}
		$modal.on('click', '.wcs3dm-modal-prefix', function (ev) {
			ev.preventDefault();
			load($(this).data('prefix'));
		});
		$modal.on('click', '.wcs3dm-modal-pick', function () {
			var key = $(this).data('key');
			post('wcs3dm_select_object', { key: key }).done(function (resp) {
				if (resp.success) {
					cb(resp.data);
					$modal.remove();
				} else {
					alert((resp.data && resp.data.message) || 'Failed');
				}
			});
		});
		load($('#wcs3dm-browse-prefix').val() || '');
	}

	$(document).on('click', '.wcs3dm-pick-object', function () {
		var key = $(this).data('key');
		post('wcs3dm_select_object', { key: key }).done(function (resp) {
			if (resp.success) {
				window.prompt('S3 reference (copy into product download URL):', resp.data.reference);
			}
		});
	});

	function discoverStatusClass(status) {
		if (status === 's3') return 'passed';
		if (status === 'local') return 'info';
		if (status === 'missing') return 'failed';
		return 'warning';
	}

	function renderDiscover(data) {
		var s = data.summary || {};
		var html = '<p class="description">Page ' + escapeHtml(data.page) + ' of ' + escapeHtml(data.pages) + '. Counts below are for this page only.</p>';
		html += '<div class="wcs3dm-metrics">';
		html += '<div class="wcs3dm-metric"><span>On S3</span><strong>' + escapeHtml(s.s3 || 0) + '</strong><div>copied to the bucket</div></div>';
		html += '<div class="wcs3dm-metric"><span>On this server</span><strong>' + escapeHtml(s.local || 0) + '</strong><div>' + escapeHtml(formatBytes(s.eligible_bytes)) + ' still local</div></div>';
		html += '<div class="wcs3dm-metric"><span>Website link, file missing</span><strong>' + escapeHtml(s.unsupported || 0) + '</strong><div>not on disk and not on S3</div></div>';
		html += '<div class="wcs3dm-metric"><span>Missing</span><strong>' + escapeHtml(s.missing || 0) + '</strong><div>no file found</div></div>';
		html += '</div>';

		if (!(data.items || []).length) {
			html += '<p>No downloadable files on this page.</p>';
		} else {
			html += '<table class="widefat striped wcs3dm-verify-table"><thead><tr><th>Product</th><th>File the customer gets</th><th>Where it is</th><th>Size on this server</th></tr></thead><tbody>';
			data.items.forEach(function (i) {
				var size = Number(i.size) > 0 ? formatBytes(i.size) : '—';
				html += '<tr>' +
					'<td>' + escapeHtml(i.product_name || '') + '<br><span class="description">#' + escapeHtml(i.product_id) + '</span></td>' +
					'<td><strong>' + escapeHtml(i.filename || i.name || '') + '</strong>' +
					(i.name ? '<br><span class="description">' + escapeHtml(i.name) + '</span>' : '') + '</td>' +
					'<td><span class="wcs3dm-status-' + discoverStatusClass(i.status) + '">' + escapeHtml(i.stored_as || i.status) + '</span>' +
					'<br><code class="wcs3dm-path">' + escapeHtml(i.location || '') + '</code>' +
					'<br><span class="description">' + escapeHtml(i.explanation || '') + '</span></td>' +
					'<td>' + escapeHtml(size) + '</td></tr>';
			});
			html += '</tbody></table>';
		}

		if (data.pages > 1) {
			html += '<p class="wcs3dm-pager">';
			if (data.page > 1) {
				html += '<button type="button" class="button wcs3dm-discover-page" data-page="' + (data.page - 1) + '">Previous</button> ';
			}
			if (data.page < data.pages) {
				html += '<button type="button" class="button wcs3dm-discover-page" data-page="' + (data.page + 1) + '">Next</button>';
			}
			html += '</p>';
		}
		$('#wcs3dm-discover-results').html(html);
	}

	function loadDiscover(page) {
		if (!$('#wcs3dm-discover-results').length) return;
		$('#wcs3dm-discover-results').html('<p class="description">Checking products…</p>');
		post('wcs3dm_discover', { page: page || 1 }).done(function (resp) {
			if (!resp.success) {
				$('#wcs3dm-discover-results').html('<p>Could not load the product list.</p>');
				return;
			}
			renderDiscover(resp.data || {});
		}).fail(function () {
			$('#wcs3dm-discover-results').html('<p>Could not load the product list.</p>');
		});
	}

	if ($('#wcs3dm-discover-results[data-autoload]').length) {
		loadDiscover(1);
	}

	function renderOrphans(data) {
		var totals = data.totals || {};
		var items = data.items || [];
		var html = '<div class="wcs3dm-metrics">';
		html += '<div class="wcs3dm-metric"><span>Unused files</span><strong>' + escapeHtml(totals.files || 0) + '</strong></div>';
		html += '<div class="wcs3dm-metric"><span>Can be deleted</span><strong>' + escapeHtml(totals.ready || 0) + '</strong><div>' + escapeHtml(formatBytes(totals.bytes)) + ' on this server</div></div>';
		if (data.deleted_files) {
			html += '<div class="wcs3dm-metric"><span>Deleted just now</span><strong>' + escapeHtml(data.deleted_files) + '</strong><div>' + escapeHtml(formatBytes(data.deleted_bytes)) + '</div></div>';
		}
		html += '</div>';
		if (data.truncated) {
			html += '<p class="description">Showing the first 2,000 files. Refresh after deleting some to see the rest.</p>';
		}
		if (!items.length) {
			html += '<p>Every file in the downloads folder is used by a product.</p>';
			$('#wcs3dm-orphan-results').html(html);
			return;
		}
		html += '<p class="description">Selected: <strong id="wcs3dm-orphan-selected">0 files, 0 B</strong></p>';
		html += '<table class="widefat striped wcs3dm-verify-table"><thead><tr><th class="check-column"><input type="checkbox" id="wcs3dm-orphan-all" /></th><th>File</th><th>Why it is listed</th><th>Size</th></tr></thead><tbody>';
		items.forEach(function (item) {
			html += '<tr>' +
				'<td>' + (item.deletable ? '<input type="checkbox" class="wcs3dm-orphan-pick" data-bytes="' + escapeHtml(item.size) + '" value="' + escapeHtml(item.path) + '" />' : '') + '</td>' +
				'<td><strong>' + escapeHtml(item.filename || '') + '</strong><br><code class="wcs3dm-path">' + escapeHtml(item.path || '') + '</code></td>' +
				'<td>' + escapeHtml(item.reason || '') + (item.product_id ? '<br><span class="description">Product #' + escapeHtml(item.product_id) + '</span>' : '') + '</td>' +
				'<td>' + escapeHtml(formatBytes(item.size)) + '</td></tr>';
		});
		html += '</tbody></table>';
		$('#wcs3dm-orphan-results').html(html);
		updateOrphanSelection();
	}

	function updateOrphanSelection() {
		var bytes = 0;
		var count = 0;
		$('.wcs3dm-orphan-pick:checked').each(function () {
			bytes += Number($(this).data('bytes')) || 0;
			count += 1;
		});
		$('#wcs3dm-orphan-selected').text(count + ' file' + (count === 1 ? '' : 's') + ', ' + formatBytes(bytes));
	}

	function loadOrphans() {
		if (!$('#wcs3dm-orphan-results').length) return;
		$('#wcs3dm-orphan-results').html('<p class="description">Looking for unused files…</p>');
		post('wcs3dm_orphans', {}).done(function (resp) {
			if (!resp.success) {
				$('#wcs3dm-orphan-results').html('<p>' + escapeHtml((resp.data && resp.data.message) || 'Could not list unused files.') + '</p>');
				return;
			}
			renderOrphans(resp.data || {});
		}).fail(function () {
			$('#wcs3dm-orphan-results').html('<p>Could not list unused files.</p>');
		});
	}

	if ($('#wcs3dm-orphan-results[data-autoload]').length) {
		loadOrphans();
	}

	$(document).on('click', '#wcs3dm-orphans-refresh', function (e) {
		e.preventDefault();
		loadOrphans();
	});

	$(document).on('change', '#wcs3dm-orphan-all', function () {
		$('.wcs3dm-orphan-pick').prop('checked', $(this).prop('checked'));
		updateOrphanSelection();
	});

	$(document).on('change', '.wcs3dm-orphan-pick', updateOrphanSelection);

	$(document).on('click', '#wcs3dm-orphans-select', function (e) {
		e.preventDefault();
		$('.wcs3dm-orphan-pick').prop('checked', true);
		$('#wcs3dm-orphan-all').prop('checked', true);
		updateOrphanSelection();
	});

	$(document).on('click', '#wcs3dm-orphans-delete', function (e) {
		e.preventDefault();
		var paths = [];
		$('.wcs3dm-orphan-pick:checked').each(function () {
			paths.push($(this).val());
		});
		if (!paths.length) {
			window.alert('Select at least one file.');
			return;
		}
		if (!window.confirm('Delete the selected files from this server? Products that already use S3 are not changed.')) return;
		$('#wcs3dm-orphan-results').html('<p class="description">Deleting selected files…</p>');
		post('wcs3dm_delete_orphans', { confirm: $('#wcs3dm-orphans-confirm').val(), paths: paths }).done(function (resp) {
			if (!resp.success) {
				$('#wcs3dm-orphan-results').html('<p>' + escapeHtml((resp.data && resp.data.message) || 'Could not delete the files.') + '</p>');
				return;
			}
			renderOrphans(resp.data || {});
		}).fail(function () {
			$('#wcs3dm-orphan-results').html('<p>Could not delete the files.</p>');
		});
	});

	$(document).on('click', '#wcs3dm-discover', function (e) {
		e.preventDefault();
		loadDiscover(1);
	});

	$(document).on('click', '.wcs3dm-discover-page', function (e) {
		e.preventDefault();
		loadDiscover(Number($(this).data('page')) || 1);
	});

	function attachJob(jobId, message) {
		if (!jobId) return;
		state.jobId = jobId;
		$('#wcs3dm-job-controls').prop('hidden', false);
		if (message) {
			$('#wcs3dm-migration-status').html('<p>' + escapeHtml(message) + '</p>');
		}
		pollJob();
	}

	function startMigration(dry) {
		var payload = { dry_run: dry ? 1 : 0 };
		var ids = $('#wcs3dm-migrate-products').val();
		if (ids) payload.product_ids = ids;
		post('wcs3dm_start_migration', payload).done(function (resp) {
			var data = resp.data || {};
			if (data.job_id) {
				attachJob(data.job_id, data.message || 'Migration started. Keep this page open while batches run.');
				return;
			}
			$('#wcs3dm-migration-status').text(data.message || 'Failed');
		});
	}

	$(document).on('click', '#wcs3dm-migrate-dry', function (e) { e.preventDefault(); startMigration(true); });
	$(document).on('click', '#wcs3dm-migrate-run', function (e) { e.preventDefault(); startMigration(false); });

	function renderActivity(rows) {
		if (!rows.length) {
			$('#wcs3dm-activity').html('<p class="description">No file activity yet.</p>');
			return;
		}
		var html = '<table class="widefat striped wcs3dm-activity"><thead><tr><th>When</th><th>Product</th><th>File</th><th>Size</th><th>Result</th><th>Detail</th></tr></thead><tbody>';
		rows.forEach(function (row) {
			html += '<tr class="wcs3dm-row-' + escapeHtml(row.status) + '">' +
				'<td>' + escapeHtml(row.created_at || '') + '</td>' +
				'<td>' + escapeHtml(row.product_id) + '</td>' +
				'<td>' + escapeHtml(row.file || '') + (row.dry_run ? ' <em>(dry run)</em>' : '') + '</td>' +
				'<td>' + formatBytes(row.size) + '</td>' +
				'<td><span class="wcs3dm-status-' + escapeHtml(row.status === 'success' ? 'passed' : (row.status === 'failed' ? 'failed' : 'not_tested')) + '">' + escapeHtml(row.status) + '</span></td>' +
				'<td>' + escapeHtml(row.message || '') + '</td>' +
				'</tr>';
		});
		html += '</tbody></table>';
		$('#wcs3dm-activity').html(html);
	}

	function pollJob() {
		if (!state.jobId) return;
		if (state.pollTimer) clearTimeout(state.pollTimer);
		post('wcs3dm_job_status', { job_id: state.jobId, process: 1 }).done(function (resp) {
			if (!resp.success || !resp.data.job) return;
			var job = resp.data.job;
			var stats = job.stats || {};
			var summary = resp.data.summary || {};
			var working = job.status === 'queued' || job.status === 'running';
			var note = working
				? 'Working. Leave this page open so the next batch can run.'
				: 'Finished.';
			var html = '<p>Status: <strong>' + escapeHtml(job.status) + '</strong> — ' + escapeHtml(note) + '</p>';
			html += '<p><span class="wcs3dm-status-passed">Uploaded to S3: ' + (summary.success || 0) + '</span>';
			if (summary.failed) {
				html += ' | <span class="wcs3dm-status-failed">Failed: ' + summary.failed + '</span>';
			}
			html += '</p>';
			html += '<p>This run — Processed: ' + (stats.processed || 0) +
				' | New uploads: ' + (stats.success || 0) +
				' | Failed: ' + (stats.failed || 0) +
				' | Already on S3: ' + (stats.skipped || 0) + '</p>';
			if (!working && !(stats.success || 0) && (stats.skipped || 0)) {
				html += '<p>No new upload was needed. Those files are already on S3.</p>';
			}
			html += '<p class="description">Local files are not deleted.</p>';
			$('#wcs3dm-migration-status').html(html);
			renderActivity(resp.data.recent || []);
			if (!working && $('#wcs3dm-discover-results').length) {
				loadDiscover(1);
			}
			if (working) {
				state.pollTimer = setTimeout(pollJob, 2000);
			}
		});
	}

	if ($('#wcs3dm-migration-status').length) {
		post('wcs3dm_job_status', {}).done(function (resp) {
			if (resp.success && resp.data.job && resp.data.job.id) {
				attachJob(resp.data.job.id, 'Found an existing migration. Processing it now.');
			}
		});
	}

	$(document).on('click', '#wcs3dm-job-controls [data-job-action]', function (e) {
		e.preventDefault();
		if (!state.jobId) return;
		post('wcs3dm_job_action', { job_id: state.jobId, job_action: $(this).data('job-action') }).done(function (resp) {
			$('#wcs3dm-migration-status').append('<p>' + escapeHtml((resp.data && resp.data.message) || '') + '</p>');
			if (resp.success && resp.data.job_id) state.jobId = resp.data.job_id;
			pollJob();
		});
	});

	$(document).on('click', '#wcs3dm-export-csv', function (e) {
		e.preventDefault();
		post('wcs3dm_export_csv').done(function (resp) {
			if (!resp.success) return;
			var blob = new Blob([resp.data.csv], { type: 'text/csv' });
			var a = document.createElement('a');
			a.href = URL.createObjectURL(blob);
			a.download = resp.data.filename;
			a.click();
		});
	});

	function renderCleanup(data) {
		var totals = data.totals || {};
		var items = data.items || [];
		var html = '<div class="wcs3dm-metrics">';
		html += '<div class="wcs3dm-metric"><span>Local files still on disk</span><strong>' + escapeHtml(totals.files || 0) + '</strong><div>' + escapeHtml(formatBytes(totals.bytes)) + '</div></div>';
		html += '<div class="wcs3dm-metric"><span>Ready to delete</span><strong>' + escapeHtml(totals.ready_files || 0) + '</strong><div>' + escapeHtml(formatBytes(totals.ready_bytes)) + ' from local</div></div>';
		html += '<div class="wcs3dm-metric"><span>Not ready</span><strong>' + escapeHtml(totals.blocked_files || 0) + '</strong><div>' + escapeHtml(formatBytes(totals.blocked_bytes)) + ' kept for now</div></div>';
		if (totals.deleted_files) {
			html += '<div class="wcs3dm-metric"><span>Deleted this run</span><strong>' + escapeHtml(totals.deleted_files) + '</strong><div>' + escapeHtml(formatBytes(totals.deleted_bytes)) + ' removed from local</div></div>';
		}
		html += '</div>';

		if (!items.length) {
			html += '<p>No migrated files are still stored locally.</p>';
			$('#wcs3dm-cleanup-results').html(html);
			return;
		}

		if (!data.skip_grace && !(totals.ready_files || 0) && (data.grace_days || 0) > 0) {
			html += '<div class="notice notice-warning inline"><p>No file can be selected yet. Tick <strong>Delete now</strong> above. The boxes appear on each row after the check finishes. Then click <strong>Delete selected local files</strong>.</p></div>';
		}
		if (data.skip_grace && !(totals.ready_files || 0)) {
			var reasons = [];
			items.forEach(function (item) {
				var reason = item.reason || 'Not ready';
				if (reasons.indexOf(reason) === -1) reasons.push(reason);
			});
			html += '<div class="notice notice-error inline"><p>Delete now is on, but these files still cannot be removed. ' + escapeHtml(reasons.join(' ')) + '</p></div>';
		}

		html += '<p class="description">Selected ready files: <strong id="wcs3dm-cleanup-selected-size">0 B</strong></p>';
		html += '<table class="widefat striped wcs3dm-cleanup-table"><thead><tr>' +
			'<th class="check-column"><input type="checkbox" id="wcs3dm-cleanup-all" /></th>' +
			'<th>Product</th><th>Local file</th><th>S3 object</th><th>Size</th><th>Status</th></tr></thead><tbody>';

		items.forEach(function (item) {
			var products = (item.products || []).map(function (p) {
				return escapeHtml(p.id) + (p.name ? ' — ' + escapeHtml(p.name) : '');
			}).join('<br>');
			var statusClass = item.outcome === 'dry_run' || item.outcome === 'deleted' ? 'passed' : (item.outcome === 'failed' ? 'failed' : 'not_tested');
			var rowClass = item.outcome === 'failed' ? 'wcs3dm-row-failed' : (item.deletable || item.outcome === 'deleted' ? 'wcs3dm-row-success' : '');
			html += '<tr class="' + rowClass + '">' +
				'<td>' + (item.deletable ? '<input type="checkbox" class="wcs3dm-cleanup-pick" data-bytes="' + escapeHtml(item.file_size) + '" value="' + escapeHtml(item.select_path || item.local_path) + '" />' : '') + '</td>' +
				'<td>' + (products || '—') + '</td>' +
				'<td><strong>' + escapeHtml(item.filename || '') + '</strong><br><code class="wcs3dm-path">' + escapeHtml(item.local_path || '') + '</code><br><span class="description wcs3dm-cleanup-reason">' + escapeHtml(item.reason || '') + '</span></td>' +
				'<td><strong>' + escapeHtml(item.s3_filename || '') + '</strong><br><code class="wcs3dm-path">' + escapeHtml(item.object_key || '') + '</code></td>' +
				'<td>' + escapeHtml(formatBytes(item.file_size)) + '</td>' +
				'<td><span class="wcs3dm-status-' + statusClass + '">' + escapeHtml(item.outcome || '') + '</span><br><span class="description">' + escapeHtml(item.reason || '') + '</span></td>' +
				'</tr>';
		});
		html += '</tbody></table>';
		$('#wcs3dm-cleanup-results').html(html);
		if (data.skip_grace) {
			$('.wcs3dm-cleanup-pick').prop('checked', true);
			$('#wcs3dm-cleanup-all').prop('checked', $('.wcs3dm-cleanup-pick').length > 0);
		}
		updateCleanupSelection();
	}

	function updateCleanupSelection() {
		var bytes = 0;
		var count = 0;
		$('.wcs3dm-cleanup-pick:checked').each(function () {
			bytes += Number($(this).data('bytes')) || 0;
			count += 1;
		});
		$('#wcs3dm-cleanup-selected-size').text(count + ' file' + (count === 1 ? '' : 's') + ', ' + formatBytes(bytes));
	}

	function loadCleanupReport() {
		if (!$('#wcs3dm-cleanup-results').length) return;
		$('#wcs3dm-cleanup-results').html('<p class="description">Checking local files…</p>');
		post('wcs3dm_cleanup', { skip_grace: $('#wcs3dm-cleanup-skip-grace').prop('checked') ? 1 : 0 }).done(function (resp) {
			if (!resp.success) {
				$('#wcs3dm-cleanup-results').html('<p>' + escapeHtml((resp.data && resp.data.message) || 'Could not load the cleanup report.') + '</p>');
				return;
			}
			renderCleanup(resp.data || {});
		}).fail(function () {
			$('#wcs3dm-cleanup-results').html('<p>Could not load the cleanup report.</p>');
		});
	}

	loadCleanupReport();

	$(document).on('click', '#wcs3dm-cleanup-dry', function (e) {
		e.preventDefault();
		loadCleanupReport();
	});

	$(document).on('change', '#wcs3dm-cleanup-skip-grace', function () {
		loadCleanupReport();
	});

	$(document).on('change', '#wcs3dm-cleanup-all', function () {
		$('.wcs3dm-cleanup-pick').prop('checked', $(this).prop('checked'));
		updateCleanupSelection();
	});

	$(document).on('change', '.wcs3dm-cleanup-pick', updateCleanupSelection);

	$(document).on('click', '#wcs3dm-cleanup-select-ready', function (e) {
		e.preventDefault();
		$('.wcs3dm-cleanup-pick').prop('checked', true);
		$('#wcs3dm-cleanup-all').prop('checked', true);
		updateCleanupSelection();
	});

	$(document).on('click', '#wcs3dm-cleanup-execute', function (e) {
		e.preventDefault();
		var paths = [];
		$('.wcs3dm-cleanup-pick:checked').each(function () {
			paths.push($(this).val());
		});
		if (!paths.length) {
			var reason = $('.wcs3dm-cleanup-reason').first().text();
			window.alert(reason || 'Select at least one file that is ready to delete.');
			return;
		}
		if (!window.confirm(wcs3dmAdmin.i18n.confirmCleanup)) return;
		$('#wcs3dm-cleanup-results').html('<p class="description">Deleting selected local files…</p>');
		post('wcs3dm_cleanup', {
			execute: 1,
			confirm: $('#wcs3dm-cleanup-confirm').val(),
			paths: paths,
			skip_grace: $('#wcs3dm-cleanup-skip-grace').prop('checked') ? 1 : 0
		}).done(function (resp) {
			if (!resp.success) {
				$('#wcs3dm-cleanup-results').html('<p>' + escapeHtml((resp.data && resp.data.message) || 'Cleanup failed.') + '</p>');
				return;
			}
			renderCleanup(resp.data || {});
		}).fail(function () {
			$('#wcs3dm-cleanup-results').html('<p>Cleanup failed.</p>');
		});
	});

	$(document).on('click', '#wcs3dm-clear-logs', function (e) {
		e.preventDefault();
		post('wcs3dm_clear_logs').done(function () {
			location.reload();
		});
	});
})(jQuery);
