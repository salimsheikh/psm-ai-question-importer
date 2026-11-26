(function() {
    const config = window.psmAdmin || {};
    const ajaxUrl = config.ajaxUrl;
    const adminPageUrl = config.adminPageUrl;
    const ajaxNonce = config.ajaxNonce;
    const tagsBySkill = config.tagsBySkill || {};
    const skillMeta = config.skillMeta || {};
    let questionFilters = config.questionFilters || {};
    let questionOrder = config.questionOrder || [];
    let masterState = config.masterState || null;
    let masterRequestId = 0;
			let masterAbort = null;
			let questionNavLoading = false;
			let questionNavRequestId = 0;

			function showToast(msg, ok = true) {
				const t = document.getElementById('psm_toast');
				if (!t) return;
				t.textContent = msg;
				t.className = 'psm-toast ' + (ok ? 'ok' : 'err');
				t.style.display = 'block';
				clearTimeout(showToast.timer);
				showToast.timer = setTimeout(() => { t.style.display = 'none'; }, 4000);
			}

			const confirmModal = document.getElementById('psm_confirm_modal');
			let confirmResolver = null;
			function settleSoftConfirm(result) {
				if (!confirmResolver) return;
				const resolve = confirmResolver;
				confirmResolver = null;
				confirmModal?.classList.remove('open');
				resolve(result);
			}
			function softConfirm({ title = 'Please confirm', message = '', confirmLabel = 'Confirm', danger = true } = {}) {
				if (confirmResolver) settleSoftConfirm(false);
				document.getElementById('psm_confirm_title').textContent = title;
				document.getElementById('psm_confirm_message').textContent = message;
				const accept = document.getElementById('psm_confirm_accept');
				accept.textContent = confirmLabel;
				accept.className = 'psm-btn ' + (danger ? 'psm-confirm-danger' : 'psm-btn-primary');
				confirmModal?.classList.add('open');
				setTimeout(() => accept?.focus(), 30);
				return new Promise(resolve => { confirmResolver = resolve; });
			}

			function updateMasterMetadata(data) {
				Object.keys(tagsBySkill).forEach(key => delete tagsBySkill[key]);
				(data.tags || []).filter(tag => tag.status === 'active').forEach(function(tag) {
					const skillId = String(tag.skill_id);
					if (!tagsBySkill[skillId]) tagsBySkill[skillId] = [];
					tagsBySkill[skillId].push({ id: Number(tag.id), name: tag.name, label: tag.name, slug: tag.slug });
				});
				(data.skills || []).forEach(function(skill) {
					skillMeta[String(skill.id)] = {
						name: skill.name,
						slug: skill.slug,
						domain_id: Number(skill.domain_id || 0),
						skill_type_id: Number(skill.skill_type_id || 0),
						parent_id: Number(skill.parent_id || 0)
					};
				});
			}

			function updateMasterCounts(counts) {
				const labels = {
					domain: 'Domains', skill_type: 'Skill Types', question_type: 'Question Types',
					difficulty: 'Difficulties', skill: 'Skills', tag: 'Tags', question: 'Questions'
				};
				document.querySelectorAll('.psm-pill-nav .psm-pill').forEach(function(link) {
					const entity = new URLSearchParams(link.search).get('entity');
					if (entity && counts[entity] !== undefined) link.textContent = labels[entity] + ' (' + counts[entity] + ')';
				});
			}

			function updateMasterUrl() {
				if (!masterState) return;
				const form = document.getElementById('psm_master_filters');
				if (!form) return;
				const params = new URLSearchParams();
				params.set('page', 'psm-ai-importer');
				params.set('tab', 'masters');
				params.set('entity', masterState.entity);
				new FormData(form).forEach(function(value, key) {
					if (key === masterState.entity + '_page' || value === '') return;
					params.set(key, String(value));
				});
				if (masterState.page > 1) params.set(masterState.entity + '_page', String(masterState.page));
				history.replaceState(null, '', adminPageUrl + '?' + params.toString());
			}

			async function loadMasterList(nextState = {}, options = {}) {
				if (!masterState) return;
				const list = document.getElementById('psm_master_list');
				const form = document.getElementById('psm_master_filters');
				if (!list || !form) return;
				masterState = Object.assign({}, masterState, nextState);
				const requestId = ++masterRequestId;
				if (masterAbort) masterAbort.abort();
				masterAbort = new AbortController();
				const fd = new FormData(form);
				fd.set('action', 'psm_ajax_get_master_list');
				fd.set('nonce', ajaxNonce);
				fd.set('entity', masterState.entity);
				fd.set(masterState.entity + '_page', String(masterState.page));
				list.classList.add('is-loading');
				list.setAttribute('aria-busy', 'true');
				try {
					const response = await fetch(ajaxUrl, { method: 'POST', body: fd, signal: masterAbort.signal });
					const result = await response.json();
					if (requestId !== masterRequestId) return;
					if (!result.success) throw new Error(result.data?.message || 'Could not load the master list.');
					const current = document.getElementById('psm_master_list');
					if (current) current.outerHTML = result.data.html;
					updateMasterCounts(result.data.counts);
					updateMasterMetadata(result.data);
					if (masterState.entity === 'question') {
						questionFilters = result.data.filters;
						questionOrder = result.data.question_order;
					}
					syncMasterSkillFilters();
					updateMasterUrl();
					if (options.scroll) document.querySelector('.psm-table-card')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
				} catch (error) {
					if (error.name !== 'AbortError') showToast(error.message || 'Network error while loading records.', false);
				} finally {
					if (requestId === masterRequestId) {
						masterAbort = null;
						const current = document.getElementById('psm_master_list');
						current?.classList.remove('is-loading');
						current?.removeAttribute('aria-busy');
					}
				}
			}

			function refreshMasterList() {
				return masterState ? loadMasterList({ page: masterState.page }) : Promise.resolve();
			}

			function syncSkillOptions(domainSelect, skillSelect) {
				if (!domainSelect || !skillSelect) return;
				const domainId = parseInt(domainSelect.value || '0', 10);
				const domainRequired = domainSelect.required && !domainId;
				skillSelect.disabled = domainRequired;
				if (domainRequired) skillSelect.value = '';
				Array.from(skillSelect.options).forEach(function(option) {
					if (!option.value) return;
					const mismatch = domainRequired || (!!domainId && parseInt(option.dataset.domainId || '0', 10) !== domainId);
					option.hidden = mismatch;
					option.disabled = mismatch;
				});
				Array.from(skillSelect.querySelectorAll('optgroup')).forEach(function(group) {
					const options = Array.from(group.querySelectorAll('option[value]'));
					group.hidden = options.length > 0 && options.every(option => option.hidden);
				});
				const selected = skillSelect.selectedOptions[0];
				if (selected && selected.value && selected.hidden) skillSelect.value = '';
			}

			function syncMasterSkillFilters() {
				syncSkillOptions(document.getElementById('psm_question_domain_filter'), document.getElementById('psm_question_skill_filter'));
				syncSkillOptions(document.getElementById('psm_tag_domain_filter'), document.getElementById('psm_tag_skill_filter'));
				syncQuestionTagFilter();
			}

			function syncQuestionTagFilter() {
				const skillFilter = document.getElementById('psm_question_skill_filter');
				const tagFilter = document.getElementById('psm_question_tag_filter');
				if (!tagFilter) return;
				const skillId = parseInt(skillFilter?.value || '0', 10);
				Array.from(tagFilter.options).forEach(function(option) {
					if (!option.value) return;
					option.hidden = !!skillId && parseInt(option.dataset.skillId || '0', 10) !== skillId;
				});
				const selected = tagFilter.selectedOptions[0];
				if (selected && selected.value && selected.hidden) tagFilter.value = '';
			}

			let filterTimer = null;
			document.addEventListener('submit', function(e) {
				const filterForm = e.target.closest('#psm_master_filters');
				if (filterForm && masterState) {
					e.preventDefault();
					clearTimeout(filterTimer);
					masterState.page = 1;
					loadMasterList();
					return;
				}
				const confirmForm = e.target.closest('form[data-psm-confirm]');
				if (confirmForm) {
					e.preventDefault();
					softConfirm({
						title: confirmForm.dataset.confirmTitle,
						message: confirmForm.dataset.confirmMessage,
						confirmLabel: confirmForm.dataset.confirmLabel,
						danger: true
					}).then(confirmed => { if (confirmed) confirmForm.submit(); });
					return;
				}
				const seederForm = e.target.closest('#psm_seeder_form');
				if (seederForm) {
					e.preventDefault();
					softConfirm({
						title: seederForm.dataset.confirmTitle,
						message: seederForm.dataset.confirmMessage,
						confirmLabel: seederForm.dataset.confirmLabel,
						danger: false
					}).then(confirmed => { if (confirmed) seederForm.submit(); });
				}
			}, true);

			document.addEventListener('input', function(e) {
				if (!masterState || !e.target.matches('#psm_master_filters input[type="search"]')) return;
				clearTimeout(filterTimer);
				const query = e.target.value.trim();
				if (query.length > 0 && query.length < 3) return;
				filterTimer = setTimeout(function() {
					masterState.page = 1;
					loadMasterList();
				}, 3000);
			}, true);

			document.addEventListener('change', function(e) {
				if (!masterState || !e.target.closest('#psm_master_filters')) return;
				if (e.target.id === 'psm_question_skill_filter' || e.target.id === 'psm_question_domain_filter' || e.target.id === 'psm_tag_domain_filter') syncMasterSkillFilters();
				clearTimeout(filterTimer);
				masterState.page = 1;
				loadMasterList();
			}, true);

			document.addEventListener('click', async function(e) {
				if (!e.target?.closest) return;
				const reset = e.target.closest('[data-psm-reset-filters]');
				if (reset && masterState) {
					e.preventDefault();
					const form = document.getElementById('psm_master_filters');
					clearTimeout(filterTimer);
					form.querySelectorAll('input[type="search"], select').forEach(control => { control.value = ''; });
					syncMasterSkillFilters();
					masterState.page = 1;
					loadMasterList();
					return;
				}
				const perPageButton = e.target.closest('#psm_save_per_page');
				if (perPageButton) {
					e.preventDefault();
					perPageButton.disabled = true;
					const fd = new FormData();
					fd.append('action', 'psm_ajax_save_per_page');
					fd.append('nonce', ajaxNonce);
					fd.append('per_page', document.getElementById('psm_per_page')?.value || '25');
					try {
						const response = await fetch(ajaxUrl, { method: 'POST', body: fd });
						const result = await response.json();
						if (!result.success) throw new Error(result.data?.message || 'Could not save pagination.');
						showToast(result.data.message, true);
						if (masterState) {
							masterState.page = 1;
							await loadMasterList();
						}
					} catch (error) {
						showToast(error.message || 'Network error while saving pagination.', false);
					} finally {
						perPageButton.disabled = false;
					}
					return;
				}
				const pageButton = e.target.closest('.psm-page-btn[data-page]');
				if (pageButton && !pageButton.classList.contains('is-disabled') && !pageButton.classList.contains('is-current') && masterState) {
					e.preventDefault();
					masterState.page = parseInt(pageButton.dataset.page, 10) || 1;
					loadMasterList({}, { scroll: true });
					return;
				}
				if (e.target.closest('#psm_confirm_cancel') || e.target.closest('#psm_confirm_close')) {
					settleSoftConfirm(false);
					return;
				}
				if (e.target.closest('#psm_confirm_accept')) {
					settleSoftConfirm(true);
					return;
				}
				if (e.target === confirmModal) {
					settleSoftConfirm(false);
					return;
				}
				const deleteButton = e.target.closest('.psm-ajax-delete');
				if (deleteButton) {
					e.preventDefault();
					const entity = deleteButton.dataset.entity;
					const id = deleteButton.dataset.id;
					const name = deleteButton.dataset.name || 'this record';
					const confirmed = await softConfirm({
						title: 'Delete ' + entity.replace(/_/g, ' ') + '?',
						message: 'Are you sure you want to delete "' + name + ' (#' + id + ')"? This action cannot be undone.',
						confirmLabel: 'Delete'
					});
					if (!confirmed) return;
					deleteButton.disabled = true;
					const fd = new FormData();
					fd.append('action', 'psm_ajax_delete_master');
					fd.append('nonce', ajaxNonce);
					fd.append('entity', entity);
					fd.append('record_id', id);
					try {
						const response = await fetch(ajaxUrl, { method: 'POST', body: fd });
						const result = await response.json();
						if (!result.success) throw new Error(result.data?.message || 'Delete blocked.');
						showToast(result.data.message, true);
						if (masterState && masterState.entity === entity) await refreshMasterList();
						else if (entity === 'batch') window.location.reload();
						else document.getElementById('psm-row-' + entity + '-' + id)?.remove();
					} catch (error) {
						showToast(error.message || 'Network error while deleting.', false);
					} finally {
						deleteButton.disabled = false;
					}
				}
			}, true);

			document.addEventListener('keydown', function(e) {
				if ((e.key === 'Escape' || e.keyCode === 27) && confirmModal?.classList.contains('open')) settleSoftConfirm(false);
			});

			function escapeHtml(str) {
				const div = document.createElement('div');
				div.textContent = String(str ?? '');
				return div.innerHTML;
			}

			function setActionNotice(message, isError) {
				const box = document.getElementById('psm_ai_ajax_notice');
				if (!box) return;
				box.innerHTML = message
					? '<div class="notice notice-' + (isError ? 'error' : 'info') + ' inline"><p>' + escapeHtml(message) + '</p></div>'
					: '';
			}

			// Select All Question Types
			document.getElementById('psm_select_all_qtypes')?.addEventListener('click', function() {
				document.querySelectorAll('.psm-qtype-cb').forEach(cb => cb.checked = true);
			});

			// AJAX AI Generate Preview Form + Loader
			const genForm = document.getElementById('psm_generate_form');
			genForm?.addEventListener('submit', function(e) {
				e.preventDefault();
				const loader = document.getElementById('psm_ai_loader');
				const notice = document.getElementById('psm_ai_ajax_notice');
				const submitBtn = genForm.querySelector('button[type="submit"], input[type="submit"]');

				if (notice) notice.innerHTML = '';
				if (submitBtn) submitBtn.disabled = true;
				if (loader) loader.style.display = 'flex';

				const fd = new FormData(this);
				fd.set('action', 'psm_ajax_fetch_preview');
				fd.set('nonce', ajaxNonce);

				function escapeText(str) {
					const div = document.createElement('div');
					div.textContent = String(str ?? '');
					return div.innerHTML;
				}

				fetch(ajaxUrl, { method: 'POST', body: fd })
					.then(async r => {
						const rawText = await r.text();
						try {
							return { ok: r.ok, status: r.status, json: JSON.parse(rawText), rawText };
						} catch (err) {
							return { ok: r.ok, status: r.status, json: null, rawText };
						}
					})
					.then(({ ok, status, json, rawText }) => {
						if (json && json.success && json.data && json.data.redirect_url) {
							window.location.href = json.data.redirect_url;
							return;
						}
						const msg = json?.data?.message
							|| ('HTTP ' + status + ': ' + (rawText ? rawText.replace(/<[^>]*>/g, ' ').trim().slice(0, 300) : 'Empty response'));
						if (notice) {
							notice.innerHTML = '<div class="notice notice-error inline"><p>' + escapeText(msg) + '</p></div>';
						}
						showToast(msg, false);
					})
					.catch(err => {
						const msg = 'Network error while calling AI: ' + (err && err.message ? err.message : 'Request failed');
						if (notice) {
							notice.innerHTML = '<div class="notice notice-error inline"><p>' + escapeText(msg) + '</p></div>';
						}
						showToast(msg, false);
					})
					.finally(() => {
						if (submitBtn) submitBtn.disabled = false;
						if (loader) loader.style.display = 'none';
					});
			});

			// Skill -> Tag dynamic loader on Tab 1
			const skillSelect = document.getElementById('psm_skill_select');
			const generateDomainSelect = document.getElementById('psm_generate_domain');
			const tagBox      = document.getElementById('psm_tag_box');
			function loadGenerateSkillTags() {
				if (!tagBox) return;
				const skillId = parseInt(skillSelect ? skillSelect.value : '0', 10);
				const list    = tagsBySkill[skillId] || [];
				if (!skillId || list.length === 0) {
					tagBox.innerHTML = '<em>No tags found for this skill.</em>';
				} else {
					let html = '<div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 6px;">';
					list.forEach(function(t) {
						html += '<label style="display:block;"><input type="checkbox" name="tag_ids[]" value="' + t.id + '" title="' + escapeHtml(t.slug) + '"> <code>' + escapeHtml(t.name) + '</code></label>';
					});
					html += '</div>';
					tagBox.innerHTML = html;
				}
			}
			skillSelect?.addEventListener('change', loadGenerateSkillTags);
			generateDomainSelect?.addEventListener('change', function() {
				syncSkillOptions(this, skillSelect);
				loadGenerateSkillTags();
			});

			// Create Prompt modal (Copy / Download)
			const promptModal    = document.getElementById('psm_prompt_modal');
			const promptTextarea = document.getElementById('psm_prompt_textarea');
			const promptStatus   = document.getElementById('psm_prompt_status');
			let promptFilename   = 'ai-prompt.txt';

			const openPromptModal = () => promptModal?.classList.add('open');
			const closePromptModal = () => promptModal?.classList.remove('open');

			function buildPromptFromForm(btn) {
				const form = document.getElementById('psm_generate_form');
				if (!form) return;
				const skillSel = document.getElementById('psm_skill_select');
				const domainSel = document.getElementById('psm_generate_domain');
				setActionNotice('');

				if (!domainSel || !domainSel.value) {
					const msg = 'Select a Domain in step 1 first, then select a Skill.';
					setActionNotice(msg, true);
					showToast(msg, false);
					domainSel?.scrollIntoView({ behavior: 'smooth', block: 'center' });
					domainSel?.classList.add('psm-field-error');
					setTimeout(() => domainSel?.classList.remove('psm-field-error'), 2600);
					domainSel?.focus();
					return;
				}

				if (!skillSel || !skillSel.value) {
					const msg = 'Select a Skill in step 2 first, then click Create Prompt again.';
					setActionNotice(msg, true);
					showToast(msg, false);
					skillSel?.scrollIntoView({ behavior: 'smooth', block: 'center' });
					skillSel?.classList.add('psm-field-error');
					setTimeout(() => skillSel?.classList.remove('psm-field-error'), 2600);
					skillSel?.focus();
					return;
				}

				if (!btn) btn = document.getElementById('psm_create_prompt_btn');
				if (btn) {
					btn.disabled = true;
					btn.textContent = 'Building...';
				}

				const fd = new FormData(form);
				fd.set('action', 'psm_ajax_build_prompt');
				fd.set('nonce', ajaxNonce);

				fetch(ajaxUrl, { method: 'POST', body: fd })
					.then(r => r.json())
					.then(res => {
						if (res.success && res.data && res.data.prompt) {
							setActionNotice('');
							promptTextarea.value = res.data.prompt;
							promptFilename = res.data.filename || 'ai-prompt.txt';
							if (promptStatus) {
								promptStatus.textContent = 'Skill: ' + (res.data.skill_name || skillSel.value) + ' \u2022 ' + promptFilename;
							}
							openPromptModal();
						} else {
							const msg = res.data?.message || 'Could not build the prompt. Please try again.';
							setActionNotice(msg, true);
							showToast(msg, false);
						}
					})
					.catch(err => {
						const msg = 'Network error while building the prompt: ' + (err && err.message ? err.message : 'request failed');
						setActionNotice(msg, true);
						showToast(msg, false);
					})
					.finally(() => {
						if (btn) {
							btn.disabled = false;
							btn.textContent = 'Create Prompt (Copy / Download)';
						}
					});
			}

			document.getElementById('psm_prompt_close')?.addEventListener('click', closePromptModal);
			document.getElementById('psm_prompt_close_footer')?.addEventListener('click', closePromptModal);
			promptModal?.addEventListener('click', function(e) {
				if (e.target === promptModal) closePromptModal();
			});
			document.addEventListener('keydown', function(e) {
				if ((e.key === 'Escape' || e.keyCode === 27) && promptModal?.classList.contains('open')) {
					closePromptModal();
				}
			});

			document.getElementById('psm_prompt_copy')?.addEventListener('click', function() {
				const text = promptTextarea.value || '';
				if (!text) return;
				const done = () => showToast('Prompt copied to clipboard.', true);
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(text).then(done).catch(() => {
						promptTextarea.select();
						document.execCommand('copy');
						done();
					});
				} else {
					promptTextarea.select();
					document.execCommand('copy');
					done();
				}
			});

			document.getElementById('psm_prompt_download')?.addEventListener('click', function() {
				const text = promptTextarea.value || '';
				if (!text) return;
				const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
				const url  = URL.createObjectURL(blob);
				const a    = document.createElement('a');
				a.href = url;
				a.download = promptFilename || 'ai-prompt.txt';
				document.body.appendChild(a);
				a.click();
				document.body.removeChild(a);
				URL.revokeObjectURL(url);
			});

			// Import JSON modal: open from the generator and sync the current skill/tag focus.
			const jsonImportModal = document.getElementById('psm_import_json_modal');
			const closeJsonImportModal = () => jsonImportModal?.classList.remove('open');
			function jumpToImportCard(e) {
				if (!jsonImportModal) return true;
				if (e && e.preventDefault) e.preventDefault();
				const srcSkill = document.getElementById('psm_skill_select');
				const srcDomain = document.getElementById('psm_generate_domain');
				const dstSkill = document.getElementById('psm_json_skill_select');
				const dstDomain = document.getElementById('psm_json_domain');
				if (srcDomain && dstDomain) dstDomain.value = srcDomain.value;
				syncSkillOptions(dstDomain, dstSkill);
				if (srcSkill && dstSkill && srcSkill.value) dstSkill.value = srcSkill.value;
				if (dstSkill) loadJsonFocusTags();
				const srcTags = document.querySelectorAll('#psm_tag_box input[name="tag_ids[]"]:checked');
				if (jsonFocusTag && srcTags.length === 1) jsonFocusTag.value = srcTags[0].value;
				jsonImportModal.classList.add('open');
				setTimeout(() => (srcSkill?.value ? dstSkill : dstDomain)?.focus(), 50);
				return false;
			}
			document.getElementById('psm_import_json_close')?.addEventListener('click', closeJsonImportModal);
			document.getElementById('psm_import_json_cancel')?.addEventListener('click', closeJsonImportModal);
			jsonImportModal?.addEventListener('click', function(e) {
				if (e.target === jsonImportModal) closeJsonImportModal();
			});
			document.addEventListener('keydown', function(e) {
				if ((e.key === 'Escape' || e.keyCode === 27) && jsonImportModal?.classList.contains('open')) closeJsonImportModal();
			});

			// Delegated in capture phase so plugin/theme stopPropagation cannot swallow these clicks.
			document.addEventListener('click', function(e) {
				if (!e.target || !e.target.closest) return;
				const createBtn = e.target.closest('#psm_create_prompt_btn');
				if (createBtn) {
					e.preventDefault();
					buildPromptFromForm(createBtn);
					return;
				}
				if (e.target.closest('#psm_import_json_jump')) {
					jumpToImportCard(e);
				}
			}, true);

			// Import card: populate Tag Focus from the selected skill, then sync from the main form.
			const jsonSkillSelect = document.getElementById('psm_json_skill_select');
			const jsonDomainSelect = document.getElementById('psm_json_domain');
			const jsonFocusTag    = document.getElementById('psm_json_focus_tag');

			function loadJsonFocusTags() {
				if (!jsonFocusTag) return;
				const skillId = parseInt(jsonSkillSelect ? jsonSkillSelect.value : '0', 10);
				const list    = tagsBySkill[skillId] || [];
				let html = '<option value="">&mdash; No tag focus &mdash;</option>';
				list.forEach(function(t) {
					html += '<option value="' + t.id + '">' + escapeHtml(t.label || t.name) + ' [' + escapeHtml(t.slug) + ']</option>';
				});
				jsonFocusTag.innerHTML = html;
			}

			jsonSkillSelect?.addEventListener('change', loadJsonFocusTags);
			jsonDomainSelect?.addEventListener('change', function() {
				syncSkillOptions(this, jsonSkillSelect);
				loadJsonFocusTags();
			});
			syncSkillOptions(generateDomainSelect, skillSelect);
			syncSkillOptions(jsonDomainSelect, jsonSkillSelect);
			loadJsonFocusTags();
			syncMasterSkillFilters();

			// Select all rows on Preview
			document.getElementById('psm_select_all_rows')?.addEventListener('change', function() {
				const checked = this.checked;
				document.querySelectorAll('.psm-row-cb').forEach(cb => { if (!cb.disabled) cb.checked = checked; });
			});

			// Modal Popup Logic + ESC to Close
			const modal = document.getElementById('psm_master_modal');
			const closeModal = () => modal?.classList.remove('open');
			document.getElementById('psm_modal_close')?.addEventListener('click', closeModal);
			document.getElementById('psm_modal_cancel')?.addEventListener('click', closeModal);

			modal?.addEventListener('click', function(e) {
				if (e.target === modal) {
					closeModal();
				}
			});

			document.addEventListener('keydown', function(e) {
				if ((e.key === 'Escape' || e.keyCode === 27) && modal?.classList.contains('open')) {
					closeModal();
				}
			});

			const modalForm = document.getElementById('psm_modal_form');
			const questionTypeSelect = document.getElementById('psm_m_question_type');
			const correctKeyInputs = Array.from(document.querySelectorAll('.psm-correct-key'));

			function updateQuestionType(clearFixedOptions = false) {
				if (!questionTypeSelect) return;
				const type = questionTypeSelect.value;
				const isTrueFalse = type === 'true_false';
				const showTwoOptions = type === 'two_option' || isTrueFalse;
				const wasTrueFalse = questionTypeSelect.dataset.trueFalse === '1';

				['c', 'd'].forEach(function(key) {
					const wrap = document.getElementById('psm_m_option_' + key + '_wrap');
					const input = document.getElementById('psm_m_option_' + key);
					const correct = document.getElementById('psm_m_correct_' + key);
					if (wrap) wrap.style.display = showTwoOptions ? 'none' : 'block';
					if (input) {
						input.disabled = showTwoOptions;
						input.required = !showTwoOptions;
					}
					if (correct) {
						correct.disabled = showTwoOptions;
						if (showTwoOptions) correct.checked = false;
					}
				});

				['a', 'b'].forEach(function(key) {
					const input = document.getElementById('psm_m_option_' + key);
					if (!input) return;
					input.disabled = isTrueFalse;
					input.required = !isTrueFalse;
					if (isTrueFalse) input.value = key === 'a' ? 'True' : 'False';
					else if (clearFixedOptions && wasTrueFalse && ['True', 'False'].includes(input.value)) input.value = '';
				});
				questionTypeSelect.dataset.trueFalse = isTrueFalse ? '1' : '0';
			}

			questionTypeSelect?.addEventListener('change', () => updateQuestionType(true));
			correctKeyInputs.forEach(function(input) {
				input.addEventListener('change', function() {
					if (!this.checked) return;
					const type = questionTypeSelect?.value;
					if (type !== 'four_option') {
						correctKeyInputs.forEach(other => { if (other !== this) other.checked = false; });
						return;
					}
					const selected = correctKeyInputs.filter(other => other.checked);
					if (selected.length > 3) {
						this.checked = false;
						alert('A multiple-answer question supports at most three correct answers.');
					}
				});
			});

			const questionSkillSelect = document.getElementById('psm_m_question_skill_id');
			const questionTagInput = document.getElementById('psm_m_question_tag_input');
			const questionTagList = document.getElementById('psm_m_question_tags');
			const selectedTagsBox = document.getElementById('psm_m_selected_question_tags');
			const availableTagsBox = document.getElementById('psm_m_available_question_tags');
			const tagSuggestions = document.getElementById('psm_question_tag_suggestions');
			let selectedQuestionTags = [];

			function normalizeQuestionTag(raw) {
				const skillId = parseInt(questionSkillSelect?.value || '0', 10);
				const skill = skillMeta[skillId];
				if (!skill) return '';
				let value = String(raw || '').trim();
				if (!value) return '';
				const escapedName = skill.name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
				value = value.replace(new RegExp('^\\s*' + escapedName + '\\s*[-\\/|:]*\\s*', 'iu'), '');
				let slug = value.toLowerCase().normalize('NFKD').replace(/[\u0300-\u036f]/g, '')
					.replace(/&/g, ' and ').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '')
					.replace(/c-sharp/g, 'csharp');
				const prefix = skill.slug + '-';
				while (slug.startsWith(prefix) && slug.length > prefix.length) slug = slug.slice(prefix.length);
				if (!slug || slug === skill.slug) return '';
				return prefix + slug;
			}

			function setQuestionTags(tags) {
				selectedQuestionTags = Array.from(new Set((Array.isArray(tags) ? tags : []).map(tag => String(tag).trim()).filter(Boolean)));
				if (questionTagList) questionTagList.value = selectedQuestionTags.join(', ');
				renderQuestionTagPicker();
			}

			function renderQuestionTagPicker() {
				const skillId = parseInt(questionSkillSelect?.value || '0', 10);
				const list = tagsBySkill[skillId] || [];
				if (selectedTagsBox) {
					selectedTagsBox.replaceChildren();
					selectedQuestionTags.forEach(function(slug) {
						const chip = document.createElement('span');
						chip.className = 'psm-tag-chip';
						const text = document.createElement('span');
						text.textContent = slug;
						const remove = document.createElement('button');
						remove.type = 'button';
						remove.textContent = '×';
						remove.setAttribute('aria-label', 'Remove tag ' + slug);
						remove.title = 'Remove tag';
						remove.dataset.tooltip = 'Remove tag';
						remove.addEventListener('click', function() {
							setQuestionTags(selectedQuestionTags.filter(tag => tag !== slug));
						});
						chip.append(text, remove);
						selectedTagsBox.appendChild(chip);
					});
				}
				if (tagSuggestions) {
					tagSuggestions.replaceChildren();
					list.forEach(function(tag) {
						const option = document.createElement('option');
						option.value = tag.slug;
						option.label = tag.label || tag.name;
						tagSuggestions.appendChild(option);
					});
				}
				if (availableTagsBox) {
					availableTagsBox.replaceChildren();
					if (!skillId) {
						const hint = document.createElement('span');
						hint.className = 'psm-question-help';
						hint.textContent = 'Select a skill to see its tags.';
						availableTagsBox.appendChild(hint);
						return;
					}
					if (!list.length) {
						const hint = document.createElement('span');
						hint.className = 'psm-question-help';
						hint.textContent = 'No active tags yet. Type a new tag above and click Add tag.';
						availableTagsBox.appendChild(hint);
						return;
					}
					list.filter(tag => !selectedQuestionTags.includes(tag.slug)).forEach(function(tag) {
						const button = document.createElement('button');
						button.type = 'button';
						button.className = 'psm-tag-option';
						button.textContent = tag.slug;
						button.title = 'Add ' + tag.slug;
						button.dataset.tooltip = 'Add ' + tag.slug;
						button.addEventListener('click', function() { addQuestionTag(tag.slug); });
						availableTagsBox.appendChild(button);
					});
				}
			}

			function addQuestionTag(raw) {
				const slug = normalizeQuestionTag(raw);
				if (!slug) {
					alert('Select a skill and enter a valid tag name.');
					return;
				}
				if (selectedQuestionTags.includes(slug)) {
					showToast('That tag is already selected.', false);
					return;
				}
				setQuestionTags(selectedQuestionTags.concat(slug));
				if (questionTagInput) questionTagInput.value = '';
				questionTagInput?.focus();
			}

			function setQuestionEditNav(recordId) {
				const nav = document.getElementById('psm_question_edit_nav');
				const previous = document.getElementById('psm_question_previous');
				const next = document.getElementById('psm_question_next');
				const position = document.getElementById('psm_question_position');
				const id = parseInt(recordId || '0', 10);
				const index = questionOrder.findIndex(questionId => Number(questionId) === id);
				const previousId = index > 0 ? Number(questionOrder[index - 1]) : 0;
				const nextId = index >= 0 && index < questionOrder.length - 1 ? Number(questionOrder[index + 1]) : 0;
				if (nav) nav.style.display = id > 0 ? 'inline-flex' : 'none';
				if (previous) {
					previous.disabled = questionNavLoading || !previousId;
					previous.dataset.questionId = previousId || '';
				}
				if (next) {
					next.disabled = questionNavLoading || !nextId;
					next.dataset.questionId = nextId || '';
				}
				if (position) {
					position.textContent = id > 0
						? 'Question #' + id + (index >= 0 ? ' (' + (index + 1) + ' of ' + questionOrder.length + ')' : '')
						: 'Question';
				}
			}

			function populateQuestionModal(rec) {
				const domainSelect = document.getElementById('psm_m_question_domain_id');
				const skillId = rec ? (rec.skill_id || '') : '';
				domainSelect.value = rec ? (rec.domain_id || skillMeta[skillId]?.domain_id || '') : '';
				syncSkillOptions(domainSelect, questionSkillSelect);
				questionSkillSelect.value = skillId;
				document.getElementById('psm_m_question_type').value      = rec ? (rec.question_type || 'four_option') : 'four_option';
				document.getElementById('psm_m_question_difficulty').value = rec ? (rec.difficulty || 1) : 1;
				document.getElementById('psm_m_question_status').value    = rec ? (rec.status || 'draft') : 'draft';
				document.getElementById('psm_m_question_text').value      = rec ? (rec.question_text || '') : '';
				document.getElementById('psm_m_external_id').value        = rec ? (rec.external_id || '') : '';
				document.getElementById('psm_m_code_language').value     = rec ? (rec.code_language || '') : '';
				document.getElementById('psm_m_code_snippet').value      = rec ? (rec.code_snippet || '') : '';
				document.getElementById('psm_m_explanation').value        = rec ? (rec.explanation || '') : '';
				setQuestionTags(rec && Array.isArray(rec.tag_slugs) ? rec.tag_slugs : []);

				const options = rec && rec.options && typeof rec.options === 'object' ? rec.options : {};
				['a', 'b', 'c', 'd'].forEach(function(key, index) {
					const optionKey = key.toUpperCase();
					const value = options[optionKey] ?? (Array.isArray(options) ? (options[index] || '') : '');
					document.getElementById('psm_m_option_' + key).value = value;
				});
				correctKeyInputs.forEach(function(input) {
					input.checked = !!(rec && Array.isArray(rec.correct_keys) && rec.correct_keys.includes(input.value));
				});
				updateQuestionType();
				setQuestionEditNav(rec?.id || 0);
			}

			document.getElementById('psm_m_question_domain_id')?.addEventListener('change', function() {
				syncSkillOptions(this, questionSkillSelect);
				selectedQuestionTags = [];
				if (questionTagInput) questionTagInput.value = '';
				renderQuestionTagPicker();
			});
			document.getElementById('psm_m_domain_id')?.addEventListener('change', function() {
				syncSkillOptions(this, document.getElementById('psm_m_parent_skill_id'));
			});
			document.getElementById('psm_m_tag_domain_id')?.addEventListener('change', function() {
				syncSkillOptions(this, document.getElementById('psm_m_skill_id'));
			});
			questionSkillSelect?.addEventListener('change', function() {
				selectedQuestionTags = [];
				if (questionTagInput) questionTagInput.value = '';
				renderQuestionTagPicker();
			});
			document.getElementById('psm_m_question_tag_add')?.addEventListener('click', function() {
				addQuestionTag(questionTagInput?.value || '');
			});
			questionTagInput?.addEventListener('keydown', function(e) {
				if (e.key === 'Enter') {
					e.preventDefault();
					addQuestionTag(this.value);
				}
			});

			document.addEventListener('click', function(e) {
				const btn = e.target.closest('.psm-open-modal');
				if (!btn) return;
				e.preventDefault();
					const entity = btn.dataset.entity;
					const recRaw = btn.dataset.record;
					const rec    = recRaw ? JSON.parse(recRaw) : null;
					const isQuestion = entity === 'question';
					questionNavRequestId++;
					questionNavLoading = false;

					modalForm?.reset();
					selectedQuestionTags = [];
					if (questionTagInput) questionTagInput.value = '';
					renderQuestionTagPicker();
					setQuestionEditNav(0);
					document.getElementById('psm_m_entity').value    = entity;
					document.getElementById('psm_m_record_id').value = rec ? rec.id : 0;

					const labelEntity = entity.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
					document.getElementById('psm_modal_title').textContent = (rec ? 'Edit ' : 'Add New ') + labelEntity;
					modal?.querySelector('.psm-modal')?.classList.toggle('psm-modal-question', isQuestion);

					document.getElementById('psm_m_skill_fields').style.display      = (entity === 'skill') ? 'block' : 'none';
					document.getElementById('psm_m_tag_fields').style.display        = (entity === 'tag') ? 'block' : 'none';
					document.getElementById('psm_m_batch_fields').style.display      = (entity === 'batch') ? 'block' : 'none';
					document.getElementById('psm_m_question_fields').style.display   = isQuestion ? 'block' : 'none';
					document.getElementById('psm_m_standard_fields').style.display   = (entity === 'batch' || isQuestion) ? 'none' : 'block';
					document.getElementById('psm_m_standard_status').style.display   = isQuestion ? 'none' : 'block';

					[
						'psm_m_batch_fields',
						'psm_m_question_fields',
						'psm_m_standard_fields',
						'psm_m_skill_fields',
						'psm_m_tag_fields',
						'psm_m_standard_status'
					].forEach(function(fieldId) {
						const section = document.getElementById(fieldId);
						if (!section) return;
						const hidden = getComputedStyle(section).display === 'none';
						section.querySelectorAll('input, select, textarea').forEach(function(control) {
							control.disabled = hidden;
						});
					});

					if (isQuestion) {
						populateQuestionModal(rec);
					} else if (entity === 'batch') {
						document.getElementById('psm_m_label_name').value = rec ? (rec.label_name || '') : '';
						document.getElementById('psm_m_status').value     = rec ? (rec.status || 'completed') : 'completed';
					} else {
						document.getElementById('psm_m_name').value        = rec ? (rec.name || '') : '';
						document.getElementById('psm_m_slug').value        = rec ? (rec.slug || '') : '';
						document.getElementById('psm_m_sort_order').value  = rec ? (rec.sort_order || 10) : 10;
						document.getElementById('psm_m_description').value = rec ? (rec.description || '') : '';
						document.getElementById('psm_m_status').value      = rec ? (rec.status || 'active') : 'active';

						if (entity === 'skill') {
							const domainSelect = document.getElementById('psm_m_domain_id');
							const parentSelect = document.getElementById('psm_m_parent_skill_id');
							domainSelect.value = rec ? (rec.domain_id || '') : '';
							syncSkillOptions(domainSelect, parentSelect);
							document.getElementById('psm_m_skill_type_id').value = rec ? (rec.skill_type_id || 0) : 0;
							parentSelect.value = rec ? (rec.parent_id || rec.parent_skill_id || 0) : 0;
						}
						if (entity === 'tag') {
							const domainSelect = document.getElementById('psm_m_tag_domain_id');
							const skillSelect = document.getElementById('psm_m_skill_id');
							const skillId = rec ? (rec.skill_id || '') : '';
							domainSelect.value = rec ? (rec.domain_id || skillMeta[skillId]?.domain_id || '') : '';
							syncSkillOptions(domainSelect, skillSelect);
							skillSelect.value = skillId;
						}
					}

					modal.classList.add('open');
			}, true);

			['psm_question_previous', 'psm_question_next'].forEach(function(id) {
				document.getElementById(id)?.addEventListener('click', async function() {
					if (questionNavLoading) return;
					const questionId = this.dataset.questionId;
					if (!questionId) return;
					const sourceRecordId = document.getElementById('psm_m_record_id').value;
					questionNavLoading = true;
					setQuestionEditNav(document.getElementById('psm_m_record_id').value);
					const requestId = ++questionNavRequestId;
					const fd = new FormData();
					fd.append('action', 'psm_ajax_get_question_master');
					fd.append('nonce', ajaxNonce);
					fd.append('question_id', questionId);
					Object.entries(questionFilters).forEach(function([key, value]) {
						if (value !== '' && value !== 0) fd.append(key, value);
					});
					try {
						const response = await fetch(ajaxUrl, { method: 'POST', body: fd });
						const result = await response.json();
						if (requestId !== questionNavRequestId || !modal?.classList.contains('open') || document.getElementById('psm_m_record_id').value !== sourceRecordId) return;
						if (!result.success || !result.data?.record) throw new Error(result.data?.message || 'Could not load the question.');
						document.getElementById('psm_m_record_id').value = result.data.record.id;
						document.getElementById('psm_modal_title').textContent = 'Edit Question';
						populateQuestionModal(result.data.record);
						const body = modal?.querySelector('.psm-modal-body');
						if (body) body.scrollTop = 0;
					} catch (error) {
						if (requestId === questionNavRequestId) showToast(error.message || 'Network error while loading the question.', false);
					} finally {
						if (requestId === questionNavRequestId) {
							questionNavLoading = false;
							setQuestionEditNav(document.getElementById('psm_m_record_id').value);
						}
					}
				});
			});

			// Auto-enforce fixed slugs in slug preview
			document.getElementById('psm_m_name')?.addEventListener('input', function() {
				const recId = parseInt(document.getElementById('psm_m_record_id').value, 10);
				if (recId > 0) return;
				const v = this.value.trim().toLowerCase();
				const slugEl = document.getElementById('psm_m_slug');
				if (v === 'entity framework core') slugEl.value = 'efcore';
				else if (v === 'c#') slugEl.value = 'csharp';
				else if (v === 'c# oop') slugEl.value = 'csharp-oop';
				else if (v === 'laravel') slugEl.value = 'laravel';
			});

			// Submit Modal AJAX Save
			document.getElementById('psm_modal_form')?.addEventListener('submit', function(e) {
				e.preventDefault();
				const entity = document.getElementById('psm_m_entity').value;
				if (!this.reportValidity()) return;

				if (entity === 'question') {
					const type = questionTypeSelect?.value;
					const selected = correctKeyInputs.filter(input => input.checked);
					const validCount = type === 'four_option'
						? selected.length >= 1 && selected.length <= 3
						: selected.length === 1;
					if (!validCount) {
						alert(type === 'four_option'
							? 'Select one to three correct answers.'
							: 'Select exactly one correct answer.');
						correctKeyInputs[0]?.focus();
						return;
					}
				}

				const submitBtn = this.querySelector('button[type="submit"]');
				if (submitBtn?.disabled) return;
				if (submitBtn) submitBtn.disabled = true;
				const fd = new FormData(this);
				fd.append('action', 'psm_ajax_save_master');
				fd.append('nonce', ajaxNonce);

				fetch(ajaxUrl, { method: 'POST', body: fd })
					.then(r => r.json())
					.then(res => {
						if (res.success) {
							showToast(res.data.message, true);
							closeModal();
							if (masterState && masterState.entity === entity) refreshMasterList();
							else location.reload();
						} else {
							alert(res.data?.message || 'Error saving record.');
						}
					})
					.catch(() => alert('Network error while saving.'))
					.finally(() => { if (submitBtn) submitBtn.disabled = false; });
			});

			// 3 Separate AJAX AI Forms on Tab 4
			document.querySelectorAll('.psm-ajax-ai-form').forEach(form => {
				form.addEventListener('submit', function(e) {
					e.preventDefault();
					const fd = new FormData(this);
					fd.append('action', 'psm_ajax_save_ai_provider');
					fd.append('nonce', ajaxNonce);

					fetch(ajaxUrl, { method: 'POST', body: fd })
						.then(r => r.json())
						.then(res => {
							if (res.success) {
								showToast(res.data.message, true);
								const keyInput   = this.querySelector('[name="api_key"]');
								const clearBox   = this.querySelector('[name="clear_api_key"]');
								const wasCleared = clearBox ? clearBox.checked : false;
								const typed      = keyInput ? keyInput.value.trim() : '';
								if (keyInput) {
									// Intended behavior: the saved key is never echoed back, so clear the field after saving.
									keyInput.value = '';
									keyInput.placeholder = res.data.has_key ? 'A key is saved - leave blank to keep it' : 'Enter API key';
								}
								if (clearBox) clearBox.checked = false;
								const statusEl = document.getElementById('psm_save_status_' + this.dataset.provider);
								if (statusEl) {
									statusEl.classList.remove('err');
									let suffix;
									if (wasCleared) {
										suffix = 'key removed';
									} else if (!res.data.has_key) {
										suffix = 'no key stored';
									} else if (typed === '') {
										suffix = 'saved key kept (field left blank)';
									} else {
										suffix = 'key stored (field cleared)';
									}
									statusEl.textContent = 'Saved at ' + new Date().toLocaleTimeString() + ' \u2014 ' + suffix;
								}
								const u = res.data.usage;
								const box = document.getElementById('psm_quota_box_' + u.provider);
								if (box) {
									box.innerHTML =
										'<strong>Daily Usage:</strong> ' + u.daily_used + ' / ' + u.daily_limit + ' (Left: ' + u.daily_remaining + ')<br>' +
										'<strong>Next Daily Reset:</strong> ' + u.next_daily_reset + '<br>' +
										'<strong>Weekly Usage:</strong> ' + u.weekly_used + ' / ' + u.weekly_limit + ' (Left: ' + u.weekly_remaining + ')<br>' +
										'<strong>Next Weekly Reset:</strong> ' + u.next_weekly_reset;
								}
							} else {
								const msg = res.data?.message || 'Save failed';
								showToast(msg, false);
								const statusEl = document.getElementById('psm_save_status_' + this.dataset.provider);
								if (statusEl) {
									statusEl.classList.add('err');
									statusEl.textContent = msg;
								}
							}
						})
						.catch(() => {
							showToast('Network error', false);
							const statusEl = document.getElementById('psm_save_status_' + this.dataset.provider);
							if (statusEl) {
								statusEl.classList.add('err');
								statusEl.textContent = 'Network error while saving.';
							}
						});
				});
			});
		})();
