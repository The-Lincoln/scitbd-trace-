// SCITBD Discord daily approval bot — posts the Block 1 schedule at 05:30 BST
// and releases execution only on Approve by md.s.lincoln@gmail.com.
// Approving writes storage/approval-YYYY-MM-DD.json, which
// `php tools/run_daily.php --require-approval` checks before running.
//
// Prerequisites: npm install discord.js express dotenv
// Run persistently (listener for button clicks):
//   node tools/discord_approval/approval-bot.js
// Cron trigger (05:30 BST) + stay alive for clicks:
//   node tools/discord_approval/approval-bot.js --send
// Fire-once (no listener):
//   node tools/discord_approval/approval-bot.js --send --once
require('dotenv').config();
const fs = require('fs');
const path = require('path');
const axios = require('axios');
const {
  Client,
  GatewayIntentBits,
  ActionRowBuilder,
  ButtonBuilder,
  ButtonStyle,
  EmbedBuilder,
} = require('discord.js');

// Authorized Approver Email/Identifier
const AUTHORIZED_APPROVER_EMAIL = 'md.s.lincoln@gmail.com';
const APPROVER_DISCORD_TAG = process.env.APPROVER_DISCORD_TAG || 'md.s.lincoln';
const APPROVER_DISCORD_ID = process.env.APPROVER_DISCORD_ID || '';
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

// AgencyOS backend (parity with the Slack bot)
const AGENCYOS_BASE_URL = (process.env.AGENCYOS_BASE_URL || 'http://localhost/lead').replace(/\/$/, '');
const AGENCYOS_CRON_SECRET = process.env.AGENCYOS_CRON_SECRET || '6f54c683b5e04d99478171e6e8e5e16bfde05ed82f9eac7d87c88ad642e9ef96';

async function triggerAgencyOSCron() {
  try {
    const cronResponse = await axios.get(`${AGENCYOS_BASE_URL}/cron.php`, {
      params: { secret: AGENCYOS_CRON_SECRET },
      timeout: 10000,
    });
    const apiResponse = await axios.get(`${AGENCYOS_BASE_URL}/api.php`, {
      params: { action: 'run_due_tasks' },
      timeout: 10000,
    });
    return { success: true, cronStatus: cronResponse.status, tasksExecuted: apiResponse.data };
  } catch (error) {
    console.error('[AgencyOS API Error]:', error.message);
    return { success: false, error: error.message };
  }
}

async function logApprovalToAgencyOS(approverEmail, taskCount, outcome) {
  try {
    const response = await axios.post(
      `${AGENCYOS_BASE_URL}/log_approval.php`,
      new URLSearchParams({
        secret: AGENCYOS_CRON_SECRET,
        approver: approverEmail,
        title: `Daily Schedule Approved (${new Date().toISOString().split('T')[0]})`,
        status: 'Completed',
        outcome,
      })
    );
    console.log('[AgencyOS Logger] Activity recorded ID:', response.data.activity_id);
    return { success: true, activityId: response.data.activity_id };
  } catch (error) {
    console.error('[AgencyOS Logger Error]:', error.message);
    return { success: false, error: error.message };
  }
}

// Initialize Discord Client
const client = new Client({
  intents: [GatewayIntentBits.Guilds, GatewayIntentBits.GuildMessages],
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
      { name: '7-Touch Email Drip Nurtures', url: `${BASE_URL}/campaigns/${currentDate}-email-drip` },
      { name: 'Daily Social Content Engine', url: `${BASE_URL}/social/${currentDate}-posts` },
    ],
  };
}

// Bot Ready Event
client.once('ready', async () => {
  console.log(`[SCITBD Bot] Logged in as ${client.user.tag}`);
  if (process.argv.includes('--send')) {
    const channelId = process.env.DISCORD_CHANNEL_ID;
    if (!channelId) {
      console.error('[SCITBD Bot] DISCORD_CHANNEL_ID not set — cannot send.');
      process.exit(1);
    }
    await sendDailyApprovalRequest(channelId);
    if (process.argv.includes('--once')) {
      process.exit(0);
    }
  }
});

// Send Approval Request Function
async function sendDailyApprovalRequest(channelId) {
  const channel = await client.channels.fetch(channelId);
  const payload = getDailyTasksPayload();

  // Create Embed Message
  const embed = new EmbedBuilder()
    .setTitle(`📋 SCITBD Daily Tasks Approval Request - ${payload.date}`)
    .setDescription(
      `**Required Approver:** \`${AUTHORIZED_APPROVER_EMAIL}\`\nPlease review and approve the scheduled daily task links below before Block 1 execution (06:00 BST).`
    )
    .setColor('#0099ff')
    .addFields(
      payload.tasks.map((task, index) => ({
        name: `Task ${index + 1}`,
        value: `[${task.name}](${task.url})`,
        inline: false,
      }))
    )
    .setTimestamp()
    .setFooter({ text: 'SCITBD Autonomous Operational Engine' });

  // Create Interactive Buttons
  const row = new ActionRowBuilder().addComponents(
    new ButtonBuilder()
      .setCustomId('approve_daily_tasks')
      .setLabel('Approve Daily Schedule')
      .setStyle(ButtonStyle.Success),
    new ButtonBuilder()
      .setCustomId('reject_daily_tasks')
      .setLabel('Reject / Request Revision')
      .setStyle(ButtonStyle.Danger)
  );

  await channel.send({ embeds: [embed], components: [row] });
  console.log(`[SCITBD Bot] Approval request sent for ${payload.date}`);
}

// Handle Interaction Events (Button Clicks)
client.on('interactionCreate', async (interaction) => {
  if (!interaction.isButton()) return;

  const { customId, user } = interaction;

  // Verification Step: tag or mapped Discord user id
  const isAuthorized = user.tag === APPROVER_DISCORD_TAG || (APPROVER_DISCORD_ID !== '' && user.id === APPROVER_DISCORD_ID);

  if (!isAuthorized) {
    return interaction.reply({
      content: `❌ Unauthorized action. Only \`${AUTHORIZED_APPROVER_EMAIL}\` is authorized to approve daily tasks.`,
      ephemeral: true,
    });
  }

  if (customId === 'approve_daily_tasks') {
    const date = new Date().toISOString().split('T')[0];
    // Release the local gate FIRST so run_daily works even if AgencyOS is down.
    writeApproval(date, AUTHORIZED_APPROVER_EMAIL, 'discord');

    // AgencyOS trigger parity with the Slack bot (non-fatal on failure).
    const cronResult = await triggerAgencyOSCron();
    const logResult = await logApprovalToAgencyOS(
      AUTHORIZED_APPROVER_EMAIL,
      5,
      date,
      cronResult.success
        ? 'Approved via Discord; AgencyOS cron executed.'
        : `Approved via Discord; AgencyOS unreachable (${cronResult.error}).`
    );

    const statusLine =
      `Approved by \`${AUTHORIZED_APPROVER_EMAIL}\` at ${new Date().toLocaleTimeString()} BST`
      + (cronResult.success
        ? `\n🚀 AgencyOS Engine Activated.${logResult.success ? ` Activity \`#${logResult.activityId}\` logged.` : ''}`
        : `\n⚠️ Approval recorded, AgencyOS unreachable: \`${cronResult.error}\``);
    const updatedEmbed = EmbedBuilder.from(interaction.message.embeds[0])
      .setColor(cronResult.success ? '#00FF00' : '#FFAA00')
      .setTitle(`${cronResult.success ? '✅' : '⚠️'} Daily Tasks Approved - ${date}`)
      .addFields({ name: 'Status', value: statusLine });

    await interaction.update({ embeds: [updatedEmbed], components: [] });
    console.log(`[SCITBD Workflow] Tasks approved by ${AUTHORIZED_APPROVER_EMAIL}. AgencyOS: ${cronResult.success ? 'ok' : cronResult.error}`);
  }

  if (customId === 'reject_daily_tasks') {
    const date = new Date().toISOString().split('T')[0];
    const updatedEmbed = EmbedBuilder.from(interaction.message.embeds[0])
      .setColor('#FF0000')
      .setTitle(`❌ Daily Tasks Rejected - ${date}`)
      .addFields({
        name: 'Status',
        value: `Rejected by \`${AUTHORIZED_APPROVER_EMAIL}\`. Awaiting manual revisions.`,
      });

    await interaction.update({ embeds: [updatedEmbed], components: [] });
  }
});

// Login Bot
if (!process.env.DISCORD_BOT_TOKEN) {
  console.error('[SCITBD Bot] DISCORD_BOT_TOKEN not set. Copy .env.example to .env first.');
  process.exit(1);
}
client.login(process.env.DISCORD_BOT_TOKEN);
