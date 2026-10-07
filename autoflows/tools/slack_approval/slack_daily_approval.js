// SCITBD Slack daily approval bot (Bolt + Block Kit) — strict email verification
// (md.s.lincoln@gmail.com) via users.info before releasing the pipeline.
// Approving writes storage/approval-YYYY-MM-DD.json, which
// `php tools/run_daily.php --require-approval` checks before running.
//
// Prerequisites: npm install @slack/bolt dotenv
// Run (Socket Mode, stays alive for button clicks):
//   node tools/slack_approval/slack_daily_approval.js
// Post the request immediately on boot (cron trigger 05:30 BST):
//   TARGET_SLACK_CHANNEL=C0123456789 node tools/slack_approval/slack_daily_approval.js
require('dotenv').config();
const fs = require('fs');
const path = require('path');
const axios = require('axios');
const { App } = require('@slack/bolt');

// Authorized Approver Email
const AUTHORIZED_APPROVER_EMAIL = 'md.s.lincoln@gmail.com';
const BASE_URL = (process.env.SCITBD_BASE_URL || 'https://scit.zya.me').replace(/\/$/, '');

// Shared approval contract with run_daily.php --require-approval
function approvalFile(date) {
  return path.join(__dirname, '..', '..', 'storage', `approval-${date}.json`);
}

function writeApproval(date, approvedBy, channel) {
  const file = approvalFile(date);
  const tmp = `${file}.tmp-${process.pid}-${Date.now()}`;
  fs.writeFileSync(
    tmp,
    JSON.stringify(
      { approved: true, approved_by: approvedBy, approved_at: new Date().toISOString(), date, channel },
      null,
      2
    )
  );
  fs.renameSync(tmp, file);
}

// Initialize Slack Bolt App
const app = new App({
  token: process.env.SLACK_BOT_TOKEN,
  signingSecret: process.env.SLACK_SIGNING_SECRET,
  socketMode: true, // Enables Socket Mode without needing a public HTTP endpoint
  appToken: process.env.SLACK_APP_TOKEN,
});

// Daily Task Links Generator
function getDailyTasksPayload() {
  const currentDate = new Date().toISOString().split('T')[0];
  return {
    date: currentDate,
    tasks: [
      { name: 'Blog 1 (06:00 BST - M&E Database Software)', url: `${BASE_URL}/drafts/${currentDate}-blog-1` },
      { name: 'Blog 2 (12:00 BST - Enterprise AI Automation)', url: `${BASE_URL}/drafts/${currentDate}-blog-2` },
      { name: 'Blog 3 (22:00 BST - SaaS Chatbot Builder)', url: `${BASE_URL}/drafts/${currentDate}-blog-3` },
      { name: '7-Touch Email Drip Nurtures (Touchpoint 1-7)', url: `${BASE_URL}/campaigns/${currentDate}-email-drip` },
      { name: 'Daily Social Content Engine (LinkedIn Authority)', url: `${BASE_URL}/social/${currentDate}-posts` },
    ],
  };
}

// Generate Slack Block Kit UI Payload
function buildApprovalBlockKit(payload, status = 'PENDING', approvedBy = '') {
  const blocks = [
    {
      type: 'header',
      text: {
        type: 'plain_text',
        text: `📋 SCITBD Daily Tasks Approval Request — ${payload.date}`,
        emoji: true,
      },
    },
    {
      type: 'section',
      text: {
        type: 'mrkdwn',
        text: `*Required Approver:* \`${AUTHORIZED_APPROVER_EMAIL}\`\nPlease review and approve the scheduled daily task links before Block 1 execution (06:00 BST).`,
      },
    },
    {
      type: 'divider',
    },
  ];

  // Append Task Links
  payload.tasks.forEach((task, idx) => {
    blocks.push({
      type: 'section',
      text: {
        type: 'mrkdwn',
        text: `*Task ${idx + 1}:* <${task.url}|${task.name}>`,
      },
    });
  });

  blocks.push({ type: 'divider' });

  // Append Interactive Buttons or Status Banner
  if (status === 'PENDING') {
    blocks.push({
      type: 'actions',
      block_id: 'daily_approval_actions',
      elements: [
        {
          type: 'button',
          text: {
            type: 'plain_text',
            text: 'Approve Daily Schedule',
            emoji: true,
          },
          style: 'primary',
          action_id: 'approve_daily_tasks',
          value: JSON.stringify(payload),
        },
        {
          type: 'button',
          text: {
            type: 'plain_text',
            text: 'Reject / Request Revision',
            emoji: true,
          },
          style: 'danger',
          action_id: 'reject_daily_tasks',
          value: JSON.stringify(payload),
        },
      ],
    });
  } else if (status === 'APPROVED') {
    blocks.push({
      type: 'section',
      text: {
        type: 'mrkdwn',
        text: `✅ *APPROVED* by \`${approvedBy}\` at ${new Date().toLocaleTimeString()} BST. Pipeline released.`,
      },
    });
  } else if (status === 'REJECTED') {
    blocks.push({
      type: 'section',
      text: {
        type: 'mrkdwn',
        text: `❌ *REJECTED / REVISION REQUESTED* by \`${approvedBy}\`. Daily tasks paused.`,
      },
    });
  }

  return blocks;
}

// AgencyOS / SCITBD Backend Config
const AGENCYOS_BASE_URL = (process.env.AGENCYOS_BASE_URL || 'http://localhost/lead').replace(/\/$/, '');
const AGENCYOS_CRON_SECRET = process.env.AGENCYOS_CRON_SECRET || '6f54c683b5e04d99478171e6e8e5e16bfde05ed82f9eac7d87c88ad642e9ef96';

// Helper Function: Call AgencyOS Cron / API
async function triggerAgencyOSCron() {
  try {
    // 1. Execute Cron Engine (Runs due tasks, triggers, briefings)
    const cronResponse = await axios.get(`${AGENCYOS_BASE_URL}/cron.php`, {
      params: { secret: AGENCYOS_CRON_SECRET },
      timeout: 10000,
    });

    // 2. Trigger explicit run_due_tasks via API endpoint
    const apiResponse = await axios.get(`${AGENCYOS_BASE_URL}/api.php`, {
      params: { action: 'run_due_tasks' },
      timeout: 10000,
    });

    return {
      success: true,
      cronStatus: cronResponse.status,
      tasksExecuted: apiResponse.data,
    };
  } catch (error) {
    console.error('[AgencyOS API Error]:', error.message);
    return { success: false, error: error.message };
  }
}

// Function to Send Daily Approval Request to Slack Channel
async function triggerDailyApprovalRequest(channelId) {
  const payload = getDailyTasksPayload();
  const blocks = buildApprovalBlockKit(payload, 'PENDING');

  await app.client.chat.postMessage({
    channel: channelId,
    text: `Daily Tasks Approval Request - ${payload.date}`,
    blocks: blocks,
  });
  console.log(`[SCITBD Slack] Sent daily approval request for ${payload.date}`);
}

// Handle 'Approve' Button Click Event
app.action('approve_daily_tasks', async ({ ack, body, action, client, respond }) => {
  await ack();

  const userId = body.user.id;
  const payload = JSON.parse(action.value);

  // Fetch Slack User Profile to verify email
  const userInfo = await client.users.info({ user: userId });
  const userEmail = userInfo.user?.profile?.email;

  if (userEmail !== AUTHORIZED_APPROVER_EMAIL) {
    // Return ephemeral notification to unauthorized user
    await respond({
      text: `❌ Unauthorized action. Only \`${AUTHORIZED_APPROVER_EMAIL}\` is authorized to approve daily tasks.`,
      response_type: 'ephemeral',
    });
    return;
  }

  // Release the pipeline FIRST: durable approval file for run_daily --require-approval.
  // The local gate works even if the AgencyOS backend is unreachable.
  const today = payload.date || new Date().toISOString().split('T')[0];
  writeApproval(today, userEmail, 'slack');

  // Acknowledge approval and trigger AgencyOS backend
  await respond({
    text: `⏳ *Approval confirmed by ${userEmail}*. Triggering AgencyOS Cron Pipeline...`,
    replace_original: false,
  });

  // Function to log approval into AgencyOS SQLite DB
  const logResult = await logApprovalToAgencyOS(userEmail, (payload.tasks || []).length, today);

  // Call AgencyOS cron.php
  const executionResult = await triggerAgencyOSCron();

  if (executionResult.success) {
    // Update Slack UI to Approved State
    const updatedBlocks = buildApprovalBlockKit(payload, 'APPROVED', userEmail);
    updatedBlocks.push({
      type: 'section',
      text: {
        type: 'mrkdwn',
        text: `🚀 *AgencyOS Engine Activated:* \`cron.php\` and \`run_due_tasks\` executed successfully.`
          + (logResult.success ? ` Activity \`#${logResult.activityId}\` logged.` : ` (activity log: ${logResult.error})`),
      },
    });

    await respond({
      blocks: updatedBlocks,
      replace_original: true,
    });
    console.log(`[SCITBD Workflow] AgencyOS Cron successfully triggered by ${userEmail}`);
  } else {
    // Alert failure in Slack
    await respond({
      text: `⚠️ *Approval recorded*, but failed to trigger AgencyOS backend: \`${executionResult.error}\``,
      replace_original: false,
    });
  }
});

// Function to log approval into AgencyOS SQLite DB (tools/log_approval.php contract)
async function logApprovalToAgencyOS(approverEmail, taskCount, date) {
  try {
    const response = await axios.post(
      `${AGENCYOS_BASE_URL}/log_approval.php`,
      new URLSearchParams({
        secret: AGENCYOS_CRON_SECRET,
        approver: approverEmail,
        title: `Daily Schedule Approved (${date})`,
        status: 'Completed',
        outcome: `Successfully verified ${approverEmail} and triggered ${taskCount} daily operational tasks.`,
      })
    );
    console.log('[AgencyOS Logger] Activity recorded ID:', response.data.activity_id);
    return { success: true, activityId: response.data.activity_id };
  } catch (error) {
    console.error('[AgencyOS Logger Error]:', error.message);
    return { success: false, error: error.message };
  }
}

// Handle 'Reject' Button Click Event
app.action('reject_daily_tasks', async ({ ack, body, action, client, respond }) => {
  await ack();

  const userId = body.user.id;
  const payload = JSON.parse(action.value);

  // Fetch Slack User Profile to verify email
  const userInfo = await client.users.info({ user: userId });
  const userEmail = userInfo.user?.profile?.email;

  if (userEmail !== AUTHORIZED_APPROVER_EMAIL) {
    await respond({
      text: `❌ Unauthorized action. Only \`${AUTHORIZED_APPROVER_EMAIL}\` is authorized to approve/reject tasks.`,
      response_type: 'ephemeral',
    });
    return;
  }

  // Update Slack Message to Rejected State
  const updatedBlocks = buildApprovalBlockKit(payload, 'REJECTED', userEmail);
  await respond({
    blocks: updatedBlocks,
    replace_original: true,
  });

  console.log(`[SCITBD Workflow] Daily tasks rejected by ${userEmail}. Awaiting revisions.`);
});

// Start Slack Bolt Application
(async () => {
  await app.start();
  console.log('⚡️ SCITBD Slack Daily Approval Bot is running!');

  // Optional: Trigger immediately for testing (Provide your target Slack Channel ID)
  if (process.env.TARGET_SLACK_CHANNEL) {
    await triggerDailyApprovalRequest(process.env.TARGET_SLACK_CHANNEL);
  }
})();
