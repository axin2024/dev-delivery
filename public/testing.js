'use strict';

const testing = {case: null, plan: null, defect: null, criteria: new Map(), criteriaAfter: null};
const outcomes = {passed: '通过', failed: '失败', skipped: '跳过', missing: '缺少执行记录'};
const defectStates = {open: '待修复', fixed: '待复测', closed: '已关闭'};
const environmentStages = {development: '开发', staging: '预发', production: '生产'};

function canTest() {
  return state.actor.roles.includes('tester');
}

function canDesignCases() {
  return canTest() || canPlan();
}

function canRegisterTargets() {
  return state.actor.roles.includes('release_manager');
}

async function readCaseCriteria(after = 0) {
  const select = $('create-case').elements.criterion_id;
  const data = await call(projectPath('/criteria/current?after=') + after);
  if (!after) {
    testing.criteria.clear();
    select.replaceChildren();
  }
  for (const item of data.items) {
    testing.criteria.set(item.id, item);
    select.add(new Option(item.requirement_code + ' / ' + item.code + '　' + item.title + ' · v' + item.version, item.id));
  }
  testing.criteriaAfter = data.next_after;
  $('more-case-criteria').hidden = data.next_after === null;
}

function readCases() {
  return readPage('/test-cases', 'test-cases', item => actionButton(
    item.code + '　' + item.title + (item.review_change_id ? '　待重新确认' : ''), () => viewTestCase(item.id)));
}

async function viewTestCase(id, after = 0) {
  const item = await call(projectPath('/test-cases/') + id + '?after=' + after);
  testing.case = item;
  const target = $('test-case-detail');
  const editor = $('revise-case');
  if (!after) {
    target.replaceChildren(textNode('h3', item.code + '　' + item.title),
      textNode('p', '当前 v' + item.version + ' · 用例版本 ID #' + item.case_version_id));
    if (item.review_change_id) target.append(actionButton('查看相关变更', () => viewChange(item.review_change_id)));
    if (canTest()) {
      target.append(actionButton('选择当前版本用于测试计划', () => {
        const field = $('create-test-plan').elements.case_version_ids;
        field.value = [...new Set([...field.value.split(/[,\s]+/).filter(Boolean), String(item.case_version_id)])].join(', ');
        $('test-plan-editor').open = true;
      }));
    }
    target.append(timelineControl('/test-cases/' + id + '/timeline'));
    editor.hidden = !canDesignCases();
    for (const name of ['title', 'steps', 'expected_result', 'junit_class', 'junit_name']) editor.elements[name].value = item[name];
  }
  for (const version of item.items) {
    const row = document.createElement('article');
    row.append(textNode('h3', 'v' + version.version + '　' + version.title),
      textNode('p', '用例版本 ID #' + version.id + ' · 标准修订 #' + version.criterion_revision_id),
      textNode('p', version.steps), textNode('p', '预期 · ' + version.expected_result),
      textNode('p', 'JUnit　' + [version.junit_class, version.junit_name].filter(Boolean).join(' / ')));
    target.append(row);
  }
  if (item.next_after !== null) {
    const more = actionButton('更多用例版本', async () => {
      await viewTestCase(id, item.next_after);
      more.remove();
    });
    target.append(more);
  }
}

function readArtifacts() {
  return readPage('/artifacts', 'artifacts', item => {
    const renderer = document.createElement('details');
    renderer.append(textNode('summary', '制品 #' + item.id + ' · ' + item.name + ' · 构建 #' + item.build_id),
      textNode('p', item.provider_run_id), textNode('code', item.sha256));
    return renderer;
  });
}

function readEnvironments() {
  return readPage('/environments', 'environments',
    item => textNode('p', '环境 #' + item.id + ' · ' + item.name + ' · ' + environmentStages[item.stage]));
}

function readTestPlans() {
  return readPage('/test-plans', 'test-plans', item => actionButton(
    item.name + '　' + item.environment_code + ' · 构建 #' + item.build_id, () => viewTestPlan(item.id)));
}

async function viewTestPlan(id) {
  const plan = await call(projectPath('/test-plans/') + id);
  testing.plan = plan;
  const target = $('test-plan-detail');
  target.replaceChildren(textNode('h3', '计划 #' + id + '　' + plan.name),
    textNode('p', '基线 #' + plan.baseline_id + ' · 构建 #' + plan.build_id + ' · 制品 #' + plan.artifact_id
      + ' · 环境 #' + plan.environment_id + ' ' + plan.environment_code),
    textNode('code', plan.sha256), textNode('h3', plan.gate.ready ? '测试门禁已通过' : '等待测试门禁'));
  const counts = plan.gate.counts;
  target.append(textNode('p', Object.entries(counts).map(([outcome, count]) => outcomes[outcome] + ' ' + count).join(' · ')));
  const cases = document.createElement('ol');
  const select = $('record-manual-test').elements.case_version_id;
  select.replaceChildren();
  for (const item of plan.gate.cases) {
    const row = textNode('li', item.title + ' · v' + item.version + '　' + outcomes[item.outcome] + '　');
    if (item.report_id) row.append(actionButton('查看执行 #' + item.result_id, () => viewTestReport(item.report_id)));
    cases.append(row);
    select.add(new Option(item.title + ' · v' + item.version, item.case_version_id));
  }
  target.append(cases);
  $('test-execution-editors').hidden = !canTest();
  $('test-report-detail').replaceChildren();
  for (const id of ['import-junit', 'record-manual-test']) $(id).reset();
  await readTestReports(id);
}

function readTestReports(id) {
  return readPage('/test-plans/' + id + '/reports', 'test-reports', item => actionButton(
    '第 ' + item.attempt + ' 次 · ' + item.source_key, () => viewTestReport(item.id)));
}

async function viewTestReport(id) {
  const report = await call(projectPath('/test-reports/') + id);
  const target = $('test-report-detail');
  target.replaceChildren(textNode('h3', '报告 #' + id + '　' + report.source_key),
    textNode('p', '执行序号 ' + report.attempt + ' · 匹配 ' + report.matched_count + ' · 计划外 ' + report.other_count),
    textNode('code', report.sha256));
  if (report.format === 'junit' && (canTest() || canRegisterTargets() || state.actor.roles.includes('admin'))) {
    const download = textNode('a', '下载原始 JUnit XML');
    download.href = projectPath('/test-reports/') + id + '/xml';
    download.setAttribute('download', 'junit-' + id + '.xml');
    const actions = document.createElement('p');
    actions.append(download);
    target.append(actions);
  }
  for (const result of report.results) {
    const row = document.createElement('article');
    row.append(textNode('h3', result.title + ' · v' + result.version + '　' + outcomes[result.outcome]),
      textNode('p', '结果 #' + result.id + (result.duration_ms === null ? '' : ' · ' + result.duration_ms + ' ms')));
    if (result.summary) row.append(textNode('p', result.summary));
    if (result.outcome === 'failed' && canTest()) {
      row.append(actionButton('使用此失败结果登记缺陷', () => {
        $('open-defect').elements.failed_result_id.value = result.id;
        $('defect-editor').open = true;
        $('defect-editor').scrollIntoView({block: 'start'});
      }));
    }
    target.append(row);
  }
}

function readDefects() {
  return readPage('/defects', 'defects', item => actionButton(
    '#' + item.id + '　' + item.title + ' · ' + defectStates[item.state], () => viewDefect(item.id)));
}

async function viewDefect(id) {
  const defect = await call(projectPath('/defects/') + id);
  testing.defect = defect;
  const target = $('defect-detail');
  target.replaceChildren(textNode('h3', defect.title + ' · ' + defectStates[defect.state]),
    textNode('p', defect.description), textNode('p', '失败构建 #' + defect.failure.build_id + ' · ' + defect.failure.summary),
    actionButton('查看原失败报告', () => viewTestReport(defect.failure.report_id)));
  if (defect.fix_artifact_id) target.append(textNode('p', '修复制品 #' + defect.fix_artifact_id + ' · 提交者 #' + defect.fixed_by));
  if (defect.retest) target.append(textNode('p', '复测通过 · 构建 #' + defect.retest.build_id),
    actionButton('查看复测报告', () => viewTestReport(defect.retest.report_id)));
  target.append(timelineControl('/defects/' + id + '/timeline'));
  const fix = $('fix-defect');
  const close = $('close-defect');
  fix.reset();
  close.reset();
  fix.hidden = defect.state === 'closed' || !state.actor.roles.includes('engineer');
  close.hidden = defect.state !== 'fixed' || !canTest() || defect.fixed_by === state.actor.account_id;
}

function readTesting() {
  $('case-editor').hidden = !canDesignCases();
  $('test-target-editors').hidden = !canRegisterTargets();
  for (const id of ['test-plan-editor', 'defect-editor']) $(id).hidden = !canTest();
  return Promise.all([readCases(), readArtifacts(), readEnvironments(), readTestPlans(), readDefects(),
    ...(!canPlan() && canDesignCases() ? [readCaseCriteria()] : [])]);
}

function resetTesting() {
  testing.case = testing.plan = testing.defect = testing.criteriaAfter = null;
  testing.criteria.clear();
  for (const id of ['test-cases', 'test-case-detail', 'artifacts', 'environments', 'test-plans',
    'test-plan-detail', 'test-reports', 'test-report-detail', 'defects', 'defect-detail']) $(id).replaceChildren();
  for (const id of ['create-case', 'revise-case', 'register-artifact', 'register-environment', 'create-test-plan',
    'import-junit', 'record-manual-test', 'open-defect', 'fix-defect', 'close-defect']) $(id).reset();
  for (const id of ['revise-case', 'test-execution-editors', 'fix-defect', 'close-defect', 'more-case-criteria']) $(id).hidden = true;
  $('create-case').elements.criterion_id.replaceChildren();
}

$('more-case-criteria').addEventListener('click', () => run(() => readCaseCriteria(testing.criteriaAfter)));
form('create-case', async fields => {
  const criterion = testing.criteria.get(Number(fields.criterion_id));
  if (!fields.junit_name.trim()) delete fields.junit_name;
  const item = await call(projectPath('/test-cases'), 'POST', {...fields, criterion_id: criterion.id, revision_id: criterion.revision_id});
  $('create-case').reset();
  await Promise.all([readCases(), viewTestCase(item.id), readTimeline()]);
});
form('revise-case', async fields => {
  const item = testing.case;
  await call(projectPath('/test-cases/') + item.id, 'PUT', {...fields, version: item.version, revision_id: item.current_criterion_revision_id});
  await Promise.all([readCases(), viewTestCase(item.id), readTimeline()]);
  showMessage('用例新版本已保存。');
});
form('register-artifact', async fields => {
  await call(projectPath('/artifacts'), 'POST', {...fields, build_id: Number(fields.build_id)});
  $('register-artifact').reset();
  await Promise.all([readArtifacts(), readTimeline()]);
});
form('register-environment', async fields => {
  await call(projectPath('/environments'), 'POST', fields);
  $('register-environment').reset();
  await Promise.all([readEnvironments(), readTimeline()]);
});
form('create-test-plan', async fields => {
  const versionIds = fields.case_version_ids.trim().split(/[,\s]+/).map(Number);
  const plan = await call(projectPath('/test-plans'), 'POST', {...fields, baseline_id: Number(fields.baseline_id),
    artifact_id: Number(fields.artifact_id), environment_id: Number(fields.environment_id), case_version_ids: versionIds});
  $('create-test-plan').reset();
  await Promise.all([readTestPlans(), viewTestPlan(plan.id), readTimeline()]);
});
form('import-junit', async fields => {
  const plan = testing.plan;
  const body = fields.report_file.size ? fields.report_file : fields.xml;
  if (!body) throw new Error('请选取 XML 文件或粘贴报告内容。');
  const report = await requestJson(projectPath('/test-plans/') + plan.id + '/reports', {method: 'POST', body, headers: {
    'Content-Type': 'application/xml', 'X-CSRF-Token': state.csrf, 'X-Delivery-Report-Key': fields.source_key,
    'X-Delivery-Attempt': fields.attempt, 'X-Delivery-Artifact-Sha256': plan.sha256,
    'X-Delivery-Build-Id': plan.build_id, 'X-Delivery-Environment-Id': plan.environment_id
  }});
  await viewTestPlan(plan.id);
  await viewTestReport(report.id);
  showMessage('JUnit 报告已保存，匹配 ' + report.matched_count + ' 项。');
});
form('record-manual-test', async fields => {
  const plan = testing.plan;
  const report = await call(projectPath('/test-plans/') + plan.id + '/manual', 'POST', {...fields,
    build_id: plan.build_id, environment_id: plan.environment_id, artifact_sha256: plan.sha256,
    attempt: Number(fields.attempt), case_version_id: Number(fields.case_version_id)});
  await viewTestPlan(plan.id);
  await viewTestReport(report.id);
});
form('open-defect', async fields => {
  const defect = await call(projectPath('/defects'), 'POST', {...fields, failed_result_id: Number(fields.failed_result_id)});
  $('open-defect').reset();
  await Promise.all([readDefects(), viewDefect(defect.id)]);
});
form('fix-defect', async fields => {
  const id = testing.defect.id;
  await call(projectPath('/defects/') + id + '/fix', 'POST', {...fields, artifact_id: Number(fields.artifact_id)});
  await Promise.all([readDefects(), viewDefect(id)]);
});
form('close-defect', async fields => {
  const id = testing.defect.id;
  await call(projectPath('/defects/') + id + '/close', 'POST', {...fields, retest_result_id: Number(fields.retest_result_id)});
  await Promise.all([readDefects(), viewDefect(id)]);
});
