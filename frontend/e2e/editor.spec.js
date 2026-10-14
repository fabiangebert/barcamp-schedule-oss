import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';
import { execSync } from 'child_process';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const rootDir = path.resolve(__dirname, '..');
const configPath = path.resolve(rootDir, 'e2e-config.json');
const dbPath = path.resolve(rootDir, '../database_test.sqlite');

function reloadConfig() {
  return JSON.parse(fs.readFileSync(configPath, 'utf8'));
}

let e2eConfig = reloadConfig();

test.describe('Barcamp Schedule Editor E2E Tests', () => {

  // Reseed after this file finishes so subsequent spec files get a clean database
  test.afterAll(async () => {
    execSync(
      `DB_PATH=${dbPath} JWT_SECRET=put-at-least-32-characters-here-1234567890 php ${rootDir}/../tests/seed_e2e.php`,
      { cwd: rootDir, timeout: 15000 }
    );
    // Re-read config so it's written to the loaded module scope
    reloadConfig();
  });

  test.beforeEach(async ({ context, page }) => {
    // Reseed database for test isolation
    execSync(
      `DB_PATH=${dbPath} JWT_SECRET=put-at-least-32-characters-here-1234567890 php ${rootDir}/../tests/seed_e2e.php`,
      { cwd: rootDir, timeout: 15000 }
    );
    e2eConfig = reloadConfig();

    await context.addInitScript((token) => {
      window.localStorage.setItem('token', token);
      window.localStorage.setItem('role', 'admin');
      window.localStorage.setItem('user', JSON.stringify({ id: 1, email: 'admin@example.com', role: 'admin' }));
      window.localStorage.setItem('userName', 'E2E Admin');
    }, e2eConfig.adminToken);

    // Capture browser console logs
    page.on('console', msg => console.log('PAGE LOG:', msg.text()));

    // Block Mercure SSE on the editor page to prevent "Update Available" modals
    // from appearing during edit operations (SSE is tested separately in realtime-sse.spec.js)
    await page.route('**/.well-known/mercure*', async route => {
      await route.abort();
    });

    // Open the seeded schedule directly
    await page.goto(`/#schedule=${e2eConfig.scheduleId}`);
    
    // Assert we loaded the correct schedule
    await expect(page.locator('h2').first()).toContainText('E2E Test Schedule');
  });

  test('should support keyboard navigation, advanced controls, drag-and-drop swap, SSE mismatch alert, room capacity warnings, bootstrap creation, and feedback ratings', async ({ page }) => {
    // -------------------------------------------------------------------------
    // A. SETUP SECOND TAB (LIVE VIEWER PAGE)
    // -------------------------------------------------------------------------
    const context = page.context();
    const viewerPage = await context.newPage();
    viewerPage.on('console', msg => console.log('VIEWER PAGE LOG:', msg.text()));
    await viewerPage.goto(`/#schedule=${e2eConfig.scheduleId}`);
    await expect(viewerPage.locator('h2').first()).toContainText('E2E Test Schedule');

    // -------------------------------------------------------------------------
    // B. ENTER EDIT MODE & KEYBOARD (TAB / SHIFT+TAB) NAVIGATION
    // -------------------------------------------------------------------------
    const editButton = page.getByRole('button', { name: /edit|bearbeiten/i });
    await expect(editButton).toBeVisible();
    await editButton.click();

    const viewButton = page.getByRole('button', { name: /view|ansehen/i });
    await expect(viewButton).toBeVisible();

    const firstCellTitle = page.locator('[data-edit-id="session-0-0-title"]');
    await expect(firstCellTitle).toBeVisible();
    await firstCellTitle.click();
    await expect(firstCellTitle).toBeFocused();

    // Tab navigates Title -> Speakers
    await page.keyboard.press('Tab');
    const firstCellSpeakers = page.locator('[data-edit-id="session-0-0-speakers"]');
    await expect(firstCellSpeakers).toBeFocused();

    // Tab navigates Speakers -> Format
    await page.keyboard.press('Tab');
    const firstCellFormat = page.locator('[data-edit-id="session-0-0-format"]');
    await expect(firstCellFormat).toBeFocused();

    // Shift+Tab reverses Format -> Speakers
    await page.keyboard.press('Shift+Tab');
    await expect(firstCellSpeakers).toBeFocused();

    // Navigate back to Title to edit it
    await page.keyboard.press('Shift+Tab');
    await expect(firstCellTitle).toBeFocused();

    // Edit the session title (leave it focused and unsaved to trigger the conflict detection)
    await page.keyboard.press('Control+A');
    await page.keyboard.press('Meta+A');
    await page.keyboard.press('Backspace');
    await page.keyboard.type('E2E Edited Session Title');

    // -------------------------------------------------------------------------
    // C. MULTI-USER CONFLICT & STALENESS ALERT (SSE intercept)
    // -------------------------------------------------------------------------
    // Intercept PATCH requests and mock a 409 Conflict status code to simulate
    // another user saving changes simultaneously
    await page.route('**/api/schedules/*', async (route, request) => {
      if (request.method() === 'PATCH') {
        await route.fulfill({
          status: 409,
          contentType: 'application/json',
          body: JSON.stringify({
            status: 'failure',
            error: 'Conflict',
            current_schedule: {
              version: 5,
              hashed_id: e2eConfig.scheduleId,
              name: 'E2E Test Schedule (Conflict)',
              data: {
                rooms: [{ name: 'Plenary', size: 'L' }, { name: 'Room A', size: 'M' }],
                timeslots: [{ name: '10:00' }, { name: '11:00' }],
                sessions: [
                  {
                    timeslot: '10:00',
                    room: 'Plenary',
                    title: 'Opening Session',
                    speakers: 'Organizers',
                    format: 'Talk'
                  },
                  {
                    timeslot: '11:00',
                    room: 'Room A',
                    title: 'Another Session',
                    speakers: 'Speakers',
                    format: 'Workshop'
                  }
                ],
                topics: [
                  { name: 'Tech', colorIndex: 1 },
                  { name: 'Design', colorIndex: 2 }
                ],
                feedback_questions: [
                  {
                    id: 'q1',
                    question: 'How likely are you to recommend this event?',
                    type: 'rating'
                  },
                  {
                    id: 'q2',
                    question: 'General Feedback',
                    type: 'text'
                  },
                  {
                    id: 'q3',
                    question: 'Which session was your favorite?',
                    type: 'multiple_choice',
                    options: ['Opening Keynote', 'Workshop', 'Panel Discussion']
                  }
                ],
                ask_for_feedback: true
              }
            }
          })
        });
      } else {
        await route.continue();
      }
    });

    // Type some text inside the session title
    const sessionTitleConflict = page.locator('[data-edit-id="session-0-0-title"]');
    await sessionTitleConflict.click();
    await sessionTitleConflict.focus();
    await page.keyboard.type(' Conflict');
    
    // Click outside to trigger the PATCH request (which will return 409)
    await page.locator('body').click({ position: { x: 10, y: 10 } });

    // Verify the Version Mismatch conflict modal pops up
    const conflictModal = page.locator('h3:has-text("Update Available")');
    await expect(conflictModal).toBeVisible();

    // Dismiss the conflict modal by clicking 'Ignore' so we can continue with the edits
    const ignoreBtn = page.getByRole('button', { name: /ignore|ignorieren/i });
    await expect(ignoreBtn).toBeVisible();
    await ignoreBtn.click();
    await expect(conflictModal).not.toBeVisible();

    // Remove the routing intercept so future saves succeed normally
    await page.unroute('**/api/schedules/*');

    // Type the title again since the previous edit was reverted by the conflict reset
    await firstCellTitle.click();
    await firstCellTitle.focus();
    await page.keyboard.press('Control+A');
    await page.keyboard.press('Meta+A');
    await page.keyboard.press('Backspace');
    await page.keyboard.type('E2E Edited Session Title');

    // Now save the edit by clicking outside on the body
    await page.locator('body').click({ position: { x: 10, y: 10 } });
    await expect(page.locator('[data-edit-id="session-0-0-title"]')).toHaveText('E2E Edited Session Title');

    // -------------------------------------------------------------------------
    // D. AUDIENCE INTEREST & ROOM CAPACITY WARNINGS
    // -------------------------------------------------------------------------
    const advancedControlsButton = page.getByRole('button', { name: /advanced controls|erweiterte steuerung/i });
    await expect(advancedControlsButton).toBeVisible();
    await advancedControlsButton.click();

    // Specify/cycle first room size (from 'L' -> 'S')
    const firstRoomSizeBtn = page.getByTitle('Room Size').first();
    await expect(firstRoomSizeBtn).toBeVisible();
    await firstRoomSizeBtn.click(); // 'L' -> 'S' (since we cycle)
    
    // Confirm first room size 'S' indicator is visible
    await expect(page.locator('span:has-text("S")').first()).toBeVisible();

    // Change the audience interest level on cell 0-0 once (from 'S' to 'M')
    const sourceCell = page.locator('[id="0-0"]'); // Plenary (size S), 10:00
    const audienceInterestBtn = sourceCell.getByTitle('Audience Interest');
    await expect(audienceInterestBtn).toBeVisible();
    await audienceInterestBtn.click(); // 'S' -> 'M'

    // Verify that the red insufficient capacity error warning icon is now visible
    // since interest level ('M' = 2) is greater than room capacity ('S' = 1)
    const errorWarningIcon = sourceCell.locator('.text-red-500');
    await expect(errorWarningIcon).toBeVisible();

    // Restore first room size ('S' -> 'M' -> 'L')
    await firstRoomSizeBtn.click(); // 'S' -> 'M'
    await firstRoomSizeBtn.click(); // 'M' -> 'L'
    await expect(page.locator('span:has-text("L")').first()).toBeVisible();
    await expect(errorWarningIcon).not.toBeVisible();

    // -------------------------------------------------------------------------
    // E. EDIT HEADERS & HIDDEN TIMESLOTS
    // -------------------------------------------------------------------------
    // Rename second room header (rename 'Room A' to 'E2E Room B')
    const roomHeaderSpan = page.locator('span:has-text("Room A")');
    await expect(roomHeaderSpan).toBeVisible();
    await roomHeaderSpan.click();
    await roomHeaderSpan.focus();
    await page.keyboard.press('Control+A');
    await page.keyboard.press('Meta+A');
    await page.keyboard.press('Backspace');
    await page.keyboard.type('E2E Room B');
    await page.locator('body').click({ position: { x: 10, y: 10 } });
    await expect(page.locator('span:has-text("E2E Room B")').first()).toBeVisible();

    // Rename second timeslot header (rename '11:00' to '12:00')
    const timeslotHeaderSpan = page.locator('span:has-text("11:00")');
    await expect(timeslotHeaderSpan).toBeVisible();
    await timeslotHeaderSpan.click();
    await timeslotHeaderSpan.focus();
    await page.keyboard.press('Control+A');
    await page.keyboard.press('Meta+A');
    await page.keyboard.press('Backspace');
    await page.keyboard.type('12:00');
    await page.locator('body').click({ position: { x: 10, y: 10 } });
    await expect(page.locator('span:has-text("12:00")').first()).toBeVisible();

    // Hide the second timeslot ('12:00')
    const hideTimeslotBtn = page.getByTitle('Hide Timeslot').nth(1);
    await expect(hideTimeslotBtn).toBeVisible();
    await hideTimeslotBtn.click(); // hides timeslot 12:00

    // -------------------------------------------------------------------------
    // F. DRAG & DROP SWAPPING
    // -------------------------------------------------------------------------
    const targetCell = page.locator('[id="1-1"]'); // E2E Room B, 12:00 (seeded title 'Another Session')

    // Confirm starting positions of titles
    await expect(sourceCell.locator('[data-edit-id$="title"]')).toHaveText('E2E Edited Session Title');
    await expect(targetCell.locator('[data-edit-id$="title"]')).toHaveText('Another Session');

    // Perform drag-and-drop swap using our high-reliability mock DragEvents
    await page.evaluate(() => {
      const sourceEl = document.getElementById('0-0');
      const targetEl = document.getElementById('1-1');

      const dataTransfer = {
        data: {
          'type': 'session',
          'data': JSON.stringify({ timeslot: '10:00', room: 'Plenary' })
        },
        setData(key, val) { this.data[key] = val; },
        getData(key) { return this.data[key] || ''; }
      };

      const dragStartEvent = new DragEvent('dragstart', { bubbles: true, cancelable: true });
      Object.defineProperty(dragStartEvent, 'dataTransfer', { value: dataTransfer });
      sourceEl.dispatchEvent(dragStartEvent);

      const dragOverEvent = new DragEvent('dragover', { bubbles: true, cancelable: true });
      Object.defineProperty(dragOverEvent, 'dataTransfer', { value: dataTransfer });
      targetEl.dispatchEvent(dragOverEvent);

      const dropEvent = new DragEvent('drop', { bubbles: true, cancelable: true });
      Object.defineProperty(dropEvent, 'dataTransfer', { value: dataTransfer });
      targetEl.dispatchEvent(dropEvent);

      const dragEndEvent = new DragEvent('dragend', { bubbles: true, cancelable: true });
      Object.defineProperty(dragEndEvent, 'dataTransfer', { value: dataTransfer });
      sourceEl.dispatchEvent(dragEndEvent);
    });

    // Exit edit mode to save edits
    await viewButton.click();
    await expect(editButton).toBeVisible();

    // -------------------------------------------------------------------------
    // G. SCHEDULE BOOTSTRAPPING (NEW SCHEDULE CREATION)
    // -------------------------------------------------------------------------
    // Go back home to the available schedules list dashboard
    const homeHeaderLogo = page.locator('h1').first();
    await expect(homeHeaderLogo).toBeVisible();
    await homeHeaderLogo.click();

    // Assert we are home on the schedule list
    await expect(page.locator('h2')).toContainText(/available schedules|verfügbare zeitpläne/i);

    // Open new schedule modal
    const newScheduleBtn = page.getByRole('button', { name: /new schedule|neuer zeitplan/i });
    await expect(newScheduleBtn).toBeVisible();
    await newScheduleBtn.click();

    // Fill new schedule form
    const nameInput = page.getByLabel(/name/i);
    await expect(nameInput).toBeVisible();
    await nameInput.fill('My E2E Bootstrapped Schedule');

    // Create schedule
    const createModalBtn = page.getByRole('button', { name: /create|erstellen/i });
    await expect(createModalBtn).toBeVisible();
    await createModalBtn.click();

    // Verify the new schedule appears in the available schedules table list
    const schedulesTable = page.locator('table');
    await expect(schedulesTable).toContainText('My E2E Bootstrapped Schedule');

    // -------------------------------------------------------------------------
    // H. EVENT FEEDBACK & LIVE AVERAGE RATING
    // -------------------------------------------------------------------------
    // Switch to our second page (User B / viewer tab)
    const feedbackBtn = viewerPage.getByRole('button', { name: 'Feedback', exact: true });
    await expect(feedbackBtn).toBeVisible();
    await feedbackBtn.click();

    // Fill the Event Feedback questionnaire modal
    // Click Star '9' out of 10 for the rating question
    const starBtn = viewerPage.getByRole('button', { name: '9', exact: true });
    await expect(starBtn).toBeVisible();
    await starBtn.click();

    // Fill the General Feedback free text box (target the visible textarea within the modal)
    const feedbackTextArea = viewerPage.locator('textarea');
    await expect(feedbackTextArea).toBeVisible();
    await feedbackTextArea.fill('This barcamp event was absolutely outstanding!');

    // Click Submit
    const submitBtn = viewerPage.getByRole('button', { name: /submit|senden/i });
    await expect(submitBtn).toBeVisible();
    await submitBtn.click();

    // Wait for the feedback submission POST to complete
    await expect(viewerPage.locator('.fixed.inset-0.z-50 h3')).toContainText(/thank you|vielen dank/i, { timeout: 10000 });

    // Close the feedback modal (target the text button, not the aria-label "Close" X button)
    const closeFeedbackBtn = viewerPage.getByRole('button', { name: /close|schließen/i }).last();
    await expect(closeFeedbackBtn).toBeVisible();
    await closeFeedbackBtn.click();

    // -------------------------------------------------------------------------
    // I. DATABASE SYNC & PERSISTENCE VERIFICATION & LIVE AVERAGE RATING
    // -------------------------------------------------------------------------
    // Reload second page (viewer tab) to confirm pulling actual persistent DB changes and feedback ratings
    await viewerPage.reload();

    // Verify navigation bar displays the updated average rating star box of '9.0' loaded from database
    const ratingStarBox = viewerPage.locator('span:has-text("9.0")');
    await expect(ratingStarBox).toBeVisible();

    // Room A renamed to E2E Room B should be visible
    await expect(viewerPage.locator('span:has-text("E2E Room B")').first()).toBeVisible();

    // Timeslot 12:00 is hidden, so in View Mode it should be completely absent
    await expect(viewerPage.locator('span:has-text("12:00")')).not.toBeVisible();

    // 10:00 is visible, Plenary cell is index 0-0, should now contain 'Another Session' (due to swap)
    const viewerSourceCell = viewerPage.locator('[id="0-0"]');
    await expect(viewerSourceCell).toContainText('Another Session');

    // -------------------------------------------------------------------------
    // J. FEATURE 3: FILE EXPORTS (DOWNLOADS)
    // -------------------------------------------------------------------------
    // Go back to first page (editor tab) and open the schedule again
    await page.goto(`/#schedule=${e2eConfig.scheduleId}`);
    
    // Click 'Documentation' to open Documentation Overview
    const docsBtn = page.getByRole('button', { name: /documentation|dokumentation/i });
    await expect(docsBtn).toBeVisible();
    await docsBtn.click();

    // Verify the documentation panel is open
    const overviewTitle = page.locator('h2:has-text("Overview")');
    await expect(overviewTitle).toBeVisible();

    // Start waiting for browser download event
    const downloadPromise = page.waitForEvent('download');
    
    // Click 'Export for LLM' button inside Overview panel
    const exportBtn = page.getByRole('button', { name: /export/i }).first();
    await expect(exportBtn).toBeVisible();
    await exportBtn.click();

    // Wait for the download process to complete
    const download = await downloadPromise;
    const downloadPath = await download.path();
    expect(downloadPath).toBeTruthy();

    // Click 'Schedule' button to close overview panel and return to the main grid
    const returnToGridBtn = page.getByRole('button', { name: /schedule|zeitplan/i });
    await expect(returnToGridBtn).toBeVisible();
    await returnToGridBtn.click();
    await expect(overviewTitle).not.toBeVisible();

    // -------------------------------------------------------------------------
    // K. FEATURE 4: LANGUAGE SELECTOR (i18n Toggle)
    // -------------------------------------------------------------------------
    // Locate the language selector button in Navbar (currently displaying 'en')
    const langBtn = page.getByRole('button', { name: 'en', exact: true });
    await expect(langBtn).toBeVisible();
    await langBtn.click();

    // Verify language translates to German (button becomes 'de', 'Documentation' translates to 'Dokumentation')
    await expect(page.getByRole('button', { name: 'de', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: "Dokumentation" })).toBeVisible();

    // Toggle back to English
    await page.getByRole('button', { name: 'de', exact: true }).click();
    await expect(page.getByRole('button', { name: 'en', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: "Documentation" })).toBeVisible();
  });

  // ---------------------------------------------------------------------------
  // L. FEATURE 5: EVENT FEEDBACK WITH ALL QUESTION TYPES (rating, multiple_choice, free_text)
  // ---------------------------------------------------------------------------
  test('should submit event feedback with rating, multiple choice, and free text', async ({ page }) => {
    await page.goto(`/#schedule=${e2eConfig.scheduleId}`);
    await expect(page.locator('h2').first()).toContainText('E2E Test Schedule');

    const feedbackBtn = page.getByRole('button', { name: 'Feedback', exact: true });
    await expect(feedbackBtn).toBeVisible();
    await feedbackBtn.click();

    await page.getByRole('button', { name: '7', exact: true }).click();

    await page.getByRole('button', { name: 'Workshop', exact: true }).click();

    const feedbackTextArea = page.locator('textarea');
    await expect(feedbackTextArea).toBeVisible();
    await feedbackTextArea.fill('Great event, loved the variety!');

    const submitBtn = page.getByRole('button', { name: /submit|senden/i });
    await expect(submitBtn).toBeVisible();
    await submitBtn.click();

    await expect(page.locator('.fixed.inset-0.z-50 h3')).toContainText(/thank you|vielen dank/i, { timeout: 10000 });

    await expect(page.getByText(/Workshop/i)).toBeVisible();

    const closeBtn = page.getByRole('button', { name: /close|schließen/i }).last();
    await expect(closeBtn).toBeVisible();
    await closeBtn.click();

    await page.reload();
    const ratingStarBox = page.locator('span:has-text("7.0")');
    await expect(ratingStarBox).toBeVisible();
  });

  // ---------------------------------------------------------------------------
  // M. FEATURE 6: SESSION EXPIRATION & AUTO-REDIRECT
  // ---------------------------------------------------------------------------
  test('should auto-redirect to login with expired query param when session expires', async ({ browser }) => {
    // Create a new browser context specifically for this test case to avoid the beforeEach token
    const context = await browser.newContext();

    // Inject our seeded EXPIRED JWT token
    await context.addInitScript((token) => {
      if (window.location.hash.includes('reason=expired')) {
        return; // Don't re-inject the expired token on redirect!
      }
      window.localStorage.setItem('token', token);
      window.localStorage.setItem('role', 'admin');
      window.localStorage.setItem('user', JSON.stringify({ email: 'admin@example.com', role: 'admin' }));
      window.localStorage.setItem('userName', 'E2E Admin');
    }, e2eConfig.expiredToken);

    const page = await context.newPage();

    // Navigate to the main dashboard (which triggers a protected fetch for schedules list)
    await page.goto('/');

    // Since the token is expired, any initial fetch or request will return 401.
    // The Axios interceptor must catch the 401, clean localStorage, and redirect home with ?reason=expired.
    // Wait for the redirect page to fully settle
    await page.waitForLoadState('networkidle');

    const finalHTML = await page.evaluate(() => document.body.innerHTML);
    console.log('DEBUG: Final redirected page HTML:', finalHTML);

    await expect(page.getByRole('heading', { name: /log in|login|anmelden/i }).first()).toBeVisible({ timeout: 10000 });

    await expect(page.getByText(/session has expired|abgelaufen/i).first()).toBeVisible();

    // Confirm that localStorage auth parameters are completely cleaned up
    const tokenAfterRedirect = await page.evaluate(() => localStorage.getItem('token'));
    expect(tokenAfterRedirect).toBeNull();

    await context.close();
  });

  // ---------------------------------------------------------------------------
  // M. USER DIRECTIVE: TOPIC ASSIGNMENT VIA DRAG & DROP
  // ---------------------------------------------------------------------------
  test('Topic assignment via drag and drop updates session cell and persists', async ({ page }) => {
    // Enter Edit Mode
    const editButton = page.getByRole('button', { name: /edit|bearbeiten/i });
    await expect(editButton).toBeVisible();
    await editButton.click();

    const viewButton = page.getByRole('button', { name: /view|ansehen/i });
    await expect(viewButton).toBeVisible();

    // Locate our session cell at 0-0 (timeslot 10:00, room Plenary)
    const sessionCell = page.locator('[id="0-0"]');
    await expect(sessionCell).toBeVisible();

    // Explicitly wait for the 'Tech' topic badge to render and be visible on the screen
    await expect(page.getByText(/tech/i).first()).toBeVisible();

    // Perform topic drag and drop ('Tech' topic at index 0 is dragged onto sessionCell)
    await page.evaluate(() => {
      const sourceEl = Array.from(document.querySelectorAll('div')).find(el => el.innerText.trim().toLowerCase() === 'tech') || document.body;
      const targetEl = document.getElementById('0-0');

      const dataTransfer = {
        data: {
          'type': 'topic',
          'data': '0' // index of Tech topic
        },
        setData(key, val) { this.data[key] = val; },
        getData(key) { return this.data[key] || ''; }
      };

      const dragStartEvent = new DragEvent('dragstart', { bubbles: true, cancelable: true });
      Object.defineProperty(dragStartEvent, 'dataTransfer', { value: dataTransfer });
      sourceEl.dispatchEvent(dragStartEvent);

      const dragOverEvent = new DragEvent('dragover', { bubbles: true, cancelable: true });
      Object.defineProperty(dragOverEvent, 'dataTransfer', { value: dataTransfer });
      targetEl.dispatchEvent(dragOverEvent);

      const dropEvent = new DragEvent('drop', { bubbles: true, cancelable: true });
      Object.defineProperty(dropEvent, 'dataTransfer', { value: dataTransfer });
      targetEl.dispatchEvent(dropEvent);

      const dragEndEvent = new DragEvent('dragend', { bubbles: true, cancelable: true });
      Object.defineProperty(dragEndEvent, 'dataTransfer', { value: dataTransfer });
      sourceEl.dispatchEvent(dragEndEvent);
    });

    // Save and Exit edit mode
    await viewButton.click();
    await expect(editButton).toBeVisible();

    // Reload the page to ensure persistence is checked against the database state
    await page.reload();

    // Re-verify the topic assignment on reload (confirms database persistence and clean render)
    await expect(page.locator('[data-edit-id="session-0-0-topic"].topic-1')).toBeVisible();
    await expect(page.locator('[id="0-0"]')).toContainText('Tech');
  });

  // ---------------------------------------------------------------------------
  // N. ROLE-BASED ACCESS CONTROL (viewer restriction)
  // ---------------------------------------------------------------------------
  test('should restrict guest/viewer from editing the schedule', async ({ browser }) => {
    // Create a new browser context specifically for this test case to bypass the beforeEach token
    const context = await browser.newContext();

    // Inject the seeded Viewer JWT token (role 'guest' - read-only attendee)
    await context.addInitScript((token) => {
      window.localStorage.setItem('token', token);
      window.localStorage.setItem('role', 'guest');
      window.localStorage.setItem('user', JSON.stringify({ email: 'guest@example.com', role: 'guest' }));
      window.localStorage.setItem('userName', 'E2E Guest');
    }, e2eConfig.guestToken);

    const page = await context.newPage();

    // Navigate directly to the seeded schedule
    await page.goto(`/#schedule=${e2eConfig.scheduleId}`);
    
    // Assert we loaded the correct schedule
    await expect(page.locator('h2').first()).toContainText('E2E Test Schedule');

    // Confirm that the 'Edit' button is completely hidden from guests/viewers
    const editButton = page.getByRole('button', { name: /edit|bearbeiten/i });
    await expect(editButton).not.toBeVisible();

    // Confirm that the 'Documentation' button is still visible for viewing
    const docsBtn = page.getByRole('button', { name: /documentation|dokumentation/i });
    await expect(docsBtn).toBeVisible();

    await context.close();
  });

  // ---------------------------------------------------------------------------
  // O. SESSION-SPECIFIC COLLABORATIVE NOTES DOCUMENTATION
  // ---------------------------------------------------------------------------
  test('should support writing, viewing, and deleting session-specific documentation notes', async ({ page }) => {
    // Log all API responses for diagnostics
    page.on('response', async response => {
      if (response.url().includes('/api/')) {
        console.log(`DEBUG API RESPONSE: [${response.request().method()}] ${response.url()} -> Status: ${response.status()}`);
        if (response.url().includes('/documentation')) {
          try {
            const body = await response.json();
            console.log(`DEBUG DOCS RESPONSE BODY [${response.request().method()}]:`, JSON.stringify(body, null, 2));
          } catch (e) {
            // not json
          }
        }
      }
    });

    // 1. In view-only mode, click cell 0-0 ('Opening Session' per seed data) to open its Documentation Modal
    const cell = page.locator('[id="0-0"]');
    await expect(cell).toBeVisible();
    await cell.click();

    // Verify the documentation modal is open for the session
    const modalHeader = page.locator('.fixed h2').first();
    await expect(modalHeader).toBeVisible();
    await expect(modalHeader).toContainText(/opening session/i);

    // 2. Add a new documentation note comment
    const commentInput = page.locator('textarea');
    await expect(commentInput).toBeVisible();
    await commentInput.fill('E2E Test collaborative session note.');

    const submitBtn = page.getByRole('button', { name: /contribute|beitragen/i });
    await expect(submitBtn).toBeVisible();
    await submitBtn.click();

    // Verify the note comment is rendered in the list of notes
    await expect(page.locator('text=E2E Test collaborative session note.')).toBeVisible();

    // 3. Close the modal by clicking the close button
    await page.locator('.fixed button').first().click();
    await expect(modalHeader).not.toBeVisible();

    // Reload the page to fetch the newly updated documentation count badge
    await page.reload();

    // 4. Verify that the documentation count badge on cell 0-0 has updated to display '1' (the new comment)
    const docBadge = cell.locator('[class*="bg-tertiary"]');
    await expect(docBadge).toHaveText("1");

    // 5. Open documentation again, delete our note, and verify count decrements back to '0'
    await cell.click();
    await expect(modalHeader).toBeVisible();

    // Locate the specific note/bubble container for our test note
    const myNoteBubble = page.locator('div.group:has-text("E2E Test")').first();

    // Hover over our specific note/bubble container to make its delete button visible
    await myNoteBubble.hover();

    // Set up dialog handler to automatically accept the browser's native confirm() dialog
    page.once('dialog', async dialog => {
      console.log('DEBUG: Playwright E2E confirm() dialog triggered with message:', dialog.message());
      await dialog.accept();
    });

    // Locate and click the Delete button (trash icon) inside our specific note bubble
    const deleteNoteBtn = myNoteBubble.getByTitle(/delete note|delete contribution|löschen/i);
    await expect(deleteNoteBtn).toBeVisible();
    console.log('DEBUG: Clicking delete note button...');
    await deleteNoteBtn.click();
    console.log('DEBUG: Clicked delete note button.');

    // Verify the note comment disappears from the modal list
    await expect(page.locator('text=E2E Test collaborative session note.')).not.toBeVisible();

    // Close the modal by clicking the close button
    await page.locator('.fixed button').first().click();
    await expect(modalHeader).not.toBeVisible();

    // Reload the page to fetch the newly updated documentation count badge
    await page.reload();

    // Verify that the documentation count badge on cell 0-0 is no longer shown (0 contributions)
    await expect(docBadge).not.toBeVisible();
  });

  // ---------------------------------------------------------------------------
  // P. ADMIN SCHEDULE DELETION
  // ---------------------------------------------------------------------------
  test('should support schedule deletion from the home available schedules list', async ({ page }) => {
    // 1. Go back home to the available schedules list dashboard
    const homeHeaderLogo = page.locator('h1').first();
    await expect(homeHeaderLogo).toBeVisible();
    await homeHeaderLogo.click();

    // Assert we are home on the schedule list
    await expect(page.locator('h2')).toContainText(/available schedules|verfügbare zeitpläne/i);

    // Verify our seeded schedule is in the list
    const scheduleRow = page.locator('tr:has-text("E2E Test Schedule")');
    await expect(scheduleRow).toBeVisible();

    // Click the Delete button in the row for the schedule
    const deleteBtn = scheduleRow.getByRole('button', { name: /delete|löschen/i });
    await expect(deleteBtn).toBeVisible();
    await deleteBtn.click();

    // Verify that the confirmation modal pops up
    const confirmModal = page.locator('[role="dialog"], div.fixed').first();
    await expect(confirmModal).toBeVisible();
    await expect(confirmModal).toContainText(/delete schedule|zeitplan löschen/i);

    // Click 'Delete/Löschen' inside the confirmation modal to finalize
    const confirmBtn = confirmModal.getByRole('button', { name: /delete|löschen/i }).last();
    await expect(confirmBtn).toBeVisible();
    await confirmBtn.click();

    // Verify the schedule is completely removed from the table list
    await expect(scheduleRow).not.toBeVisible();
  });

  // ---------------------------------------------------------------------------
  // Q. STANDALONE SESSION CELL DRAG & DROP SWAPPING
  // ---------------------------------------------------------------------------
  test('should support dragging and dropping session cells to swap sessions', async ({ page }) => {
    // Enter edit mode
    await page.getByRole('button', { name: /edit|bearbeiten/i }).click();

    const sourceCell = page.locator('[id="0-0"]');
    const targetCell = page.locator('[id="1-1"]');

    // Confirm starting titles
    await expect(sourceCell.locator('[data-edit-id$="title"]')).toHaveText('Opening Session');
    await expect(targetCell.locator('[data-edit-id$="title"]')).toHaveText('Another Session');

    // Perform drag-and-drop swap
    await page.evaluate(() => {
      const sourceEl = document.getElementById('0-0');
      const targetEl = document.getElementById('1-1');

      const dataTransfer = {
        data: {
          'type': 'session',
          'data': JSON.stringify({ timeslot: '10:00', room: 'Plenary' })
        },
        setData(key, val) { this.data[key] = val; },
        getData(key) { return this.data[key] || ''; }
      };

      const dragStartEvent = new DragEvent('dragstart', { bubbles: true, cancelable: true });
      Object.defineProperty(dragStartEvent, 'dataTransfer', { value: dataTransfer });
      sourceEl.dispatchEvent(dragStartEvent);

      const dragOverEvent = new DragEvent('dragover', { bubbles: true, cancelable: true });
      Object.defineProperty(dragOverEvent, 'dataTransfer', { value: dataTransfer });
      targetEl.dispatchEvent(dragOverEvent);

      const dropEvent = new DragEvent('drop', { bubbles: true, cancelable: true });
      Object.defineProperty(dropEvent, 'dataTransfer', { value: dataTransfer });
      targetEl.dispatchEvent(dropEvent);

      const dragEndEvent = new DragEvent('dragend', { bubbles: true, cancelable: true });
      Object.defineProperty(dragEndEvent, 'dataTransfer', { value: dataTransfer });
      sourceEl.dispatchEvent(dragEndEvent);
    });

    // Save and Exit edit mode
    await page.getByRole('button', { name: /view|ansehen/i }).click();

    // Verify swapped titles
    await expect(sourceCell.locator('.text-primary')).toContainText('Another Session');
    await expect(targetCell.locator('.text-primary')).toContainText('Opening Session');
  });

  // ---------------------------------------------------------------------------
  // R. CHANGING SESSION TOPIC VIA DROPDOWN
  // ---------------------------------------------------------------------------
  test('should change session topic using the edit mode dropdown', async ({ page }) => {
    // Enter edit mode
    await page.getByRole('button', { name: /edit|bearbeiten/i }).click();

    const topicBadge = page.locator('[data-edit-id="session-0-0-topic"]');
    await expect(topicBadge).toBeVisible();
    await topicBadge.click();

    const dropdown = page.locator('.topic-dropdown');
    await expect(dropdown).toBeVisible();

    // Select 'Design' topic
    await dropdown.getByText('Design').click();

    // Verify updated topic class/value on cell
    await expect(topicBadge).toHaveClass(/topic-2/);
    await expect(topicBadge).toHaveText('Design');

    // Save and Exit edit mode
    await page.getByRole('button', { name: /view|ansehen/i }).click();

    // Reload and check persistence
    await page.reload();
    await expect(page.locator('[data-edit-id="session-0-0-topic"]')).toHaveClass(/topic-2/);
    await expect(page.locator('[id="0-0"]')).toContainText('Design');
  });

  // ---------------------------------------------------------------------------
  // S. DELETING A SESSION
  // ---------------------------------------------------------------------------
  test('should support deleting an existing session in edit mode', async ({ page }) => {
    // Enter edit mode
    await page.getByRole('button', { name: /edit|bearbeiten/i }).click();

    const sessionCell = page.locator('[id="0-0"]');
    await expect(sessionCell).toBeVisible();

    // Hover over the cell to reveal the delete button
    await sessionCell.hover();

    const deleteBtn = sessionCell.getByRole('button', { name: /delete session/i });
    await expect(deleteBtn).toBeVisible();
    await deleteBtn.click();

    // Wait for the confirmation modal to pop up
    const confirmModal = page.locator('[role="dialog"], div.fixed').first();
    await expect(confirmModal).toBeVisible();
    
    // Click the confirmation Delete button
    const confirmBtn = confirmModal.getByRole('button', { name: /^delete$|^löschen$/i }).last();
    await expect(confirmBtn).toBeVisible();
    await confirmBtn.click();

    // Confirm session is cleared (shows empty state / default placeholder)
    await expect(sessionCell.locator('[data-edit-id="session-0-0-title"]')).toHaveText('(Title)');

    // Exit edit mode to save
    await page.getByRole('button', { name: /view|ansehen/i }).click();

    // In view mode, the empty cell should be blank
    await expect(sessionCell.locator('.text-primary')).not.toBeVisible();
  });
});
