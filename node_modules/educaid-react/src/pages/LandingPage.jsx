import React from 'react';

const legacySiteBase = (import.meta.env.VITE_LEGACY_SITE_URL || 'https://www.educ-aid.site').replace(/\/$/, '');

const quickLinks = [
  {
    label: 'Latest Announcements',
    href: `${legacySiteBase}/website/announcements.php`,
    icon: 'M3 13.5v-3A2.5 2.5 0 0 1 5.5 8H18.5A2.5 2.5 0 0 1 21 10.5v3A2.5 2.5 0 0 1 18.5 16H5.5A2.5 2.5 0 0 1 3 13.5Z',
  },
  {
    label: 'Requirements',
    href: `${legacySiteBase}/website/requirements.php`,
    icon: 'M7 4.75A2.75 2.75 0 0 1 9.75 2h4.5A2.75 2.75 0 0 1 17 4.75v14.5A2.75 2.75 0 0 1 14.25 22h-4.5A2.75 2.75 0 0 1 7 19.25Zm2.25-.25a.5.5 0 0 0-.5.5v15a.5.5 0 0 0 .5.5h5.5a.5.5 0 0 0 .5-.5v-15a.5.5 0 0 0-.5-.5Z',
  },
  {
    label: 'How It Works',
    href: `${legacySiteBase}/website/how-it-works.php`,
    icon: 'M12 2.25a9.75 9.75 0 1 0 9.75 9.75A9.76 9.76 0 0 0 12 2.25Zm1 13.5h-2v-6h2Zm0-8h-2v-2h2Z',
  },
  {
    label: 'FAQs',
    href: '#faq',
    icon: 'M11.25 18.75h1.5v1.5h-1.5Zm.75-15A7.5 7.5 0 0 0 4.5 11.25h1.5a6 6 0 1 1 6 6v1.5A7.5 7.5 0 0 0 12 3.75Z',
  },
  {
    label: 'Contact & Helpdesk',
    href: '#contact',
    icon: 'M4.5 4.5h15v9h-4.5l-3 3-3-3H4.5Z',
  },
];

const requirements = [
  'Valid School ID',
  'Enrollment Assessment Form',
  'Certificate of Indigency (after approval)',
  'Letter to the Mayor (PDF)',
  'Active email for OTP verification',
  'Mobile number for SMS updates',
  'Barangay information',
];

const howSteps = [
  {
    step: '1',
    title: 'Create & Verify',
    description: 'Register using your email and mobile number, then verify through OTP to secure your account.',
  },
  {
    step: '2',
    title: 'Apply Online',
    description: 'Complete your profile, select your barangay, and upload the required documents.',
  },
  {
    step: '3',
    title: 'Get Evaluated',
    description: 'Admins validate eligibility and post status updates with reminders when needed.',
  },
  {
    step: '4',
    title: 'Claim with QR',
    description: 'Receive your QR code and bring it on distribution day for quick claiming.',
  },
];

const announcements = [
  {
    title: 'No announcements yet',
    date: 'Official updates will appear here once posted',
    body: 'Visit the full announcements page for the latest schedules, release notices, and eligibility updates.',
    active: false,
    image: 'https://images.unsplash.com/photo-1523240795612-9a054b0db644?auto=format&fit=crop&w=1200&q=80',
  },
  {
    title: 'Distribution schedules',
    date: 'When slots open, the schedule is published first',
    body: 'Applicants should check the announcement page regularly for distribution status and open slots.',
    active: true,
    image: 'https://images.unsplash.com/photo-1513258496099-48168024aec0?auto=format&fit=crop&w=1200&q=80',
  },
  {
    title: 'Official advisories',
    date: 'CMS-controlled content remains on the legacy site',
    body: 'The municipality continues to manage announcements and eligibility changes on the official portal.',
    active: false,
    image: 'https://images.unsplash.com/photo-1497633762265-9d179a990aa6?auto=format&fit=crop&w=1200&q=80',
  },
];

const faqItems = [
  {
    q: 'Who can apply for educational assistance?',
    a: 'College students residing in General Trias, Cavite who are currently enrolled and can submit the required documents.',
  },
  {
    q: 'What documents do I need to submit?',
    a: 'A valid ID with photo, Letter to the Mayor, Certificate of Indigency from your barangay, and proof of enrollment or grades.',
  },
  {
    q: 'How do I claim assistance on distribution day?',
    a: 'After approval you will receive a QR code in your student dashboard. Present it at the venue with a valid ID.',
  },
  {
    q: 'How will I know the status of my application?',
    a: 'Sign in to track your status and watch for email notifications when review, approval, or document updates happen.',
  },
];

// Hero background image (can be configured via Vite env: VITE_HERO_BG)
const heroBackground = (import.meta.env.VITE_HERO_BG || 'https://images.unsplash.com/photo-1522202176988-66273c2fd55f?auto=format&fit=crop&w=1800&q=80').replace(/\?$/, '');

function SectionTitle({ eyebrow, title, lead, centered = false }) {
  return (
    <div className={centered ? 'mx-auto max-w-3xl text-center' : 'max-w-3xl'}>
      <p className="text-xs font-semibold uppercase tracking-[0.28em] text-cyan-700/90">{eyebrow}</p>
      <h2 className="mt-3 text-[1.7rem] font-semibold tracking-tight text-slate-950 sm:text-[2rem]">{title}</h2>
      <p className="mt-3 text-[0.96rem] leading-7 text-slate-600">{lead}</p>
    </div>
  );
}

function Card({ className = '', children }) {
  return <div className={`relative z-30 rounded-[1.25rem] border border-slate-200 bg-white shadow-2xl ${className}`}>{children}</div>;
}

function BadgeIcon({ path, className = '' }) {
  return (
    <span className={`inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-cyan-50 text-cyan-700 ring-1 ring-cyan-100 ${className}`}>
      <svg viewBox="0 0 24 24" className="h-6 w-6 fill-current" aria-hidden="true">
        <path d={path} />
      </svg>
    </span>
  );
}

export default function LandingPage() {
  return (
    <div className="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(14,165,233,0.08),_transparent_28%),radial-gradient(circle_at_top_right,_rgba(22,163,74,0.07),_transparent_26%),linear-gradient(180deg,#f8fbff_0%,#eef4fb_100%)] text-slate-800">
      <header className="sticky top-0 z-30 border-b border-slate-200/70 bg-white/90 backdrop-blur-xl shadow-[0_1px_0_rgba(15,23,42,0.04)]">
        <div className="border-b border-sky-700/15 bg-[linear-gradient(135deg,#1565c0_0%,#0d47a1_100%)] px-4 py-2 text-[0.72rem] text-white sm:px-6 lg:px-8">
          <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-center gap-x-4 gap-y-1 md:justify-between">
            <span className="inline-flex items-center gap-1.5">
              <span className="text-white/90">✉</span>
              <a className="text-white/90 transition hover:text-white" href="mailto:educaid@generaltrias.gov.ph">
                educaid@generaltrias.gov.ph
              </a>
            </span>
            <span className="hidden h-3 w-px bg-white/25 md:block" />
            <span className="inline-flex items-center gap-1.5">
              <span className="text-white/90">☎</span>
              <span>(046) 886-4454</span>
            </span>
            <span className="hidden h-3 w-px bg-white/25 md:block" />
            <span className="hidden items-center gap-1.5 md:inline-flex">
              <span className="text-white/90">🕒</span>
              <span>Mon–Fri 8:00AM - 5:00PM</span>
            </span>
          </div>
        </div>

        <div className="px-4 py-3 sm:px-6 lg:px-8">
          <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4">
            <a href="#home" className="flex items-center gap-3">
              <div className="grid h-11 w-11 place-items-center overflow-hidden rounded-2xl bg-slate-950 text-sm font-bold text-white shadow-lg shadow-slate-950/15">
                EA
              </div>
              <div>
                <div className="text-[1.02rem] font-semibold tracking-tight text-slate-950">EducAid</div>
                <div className="text-xs text-slate-500">City of General Trias</div>
              </div>
            </a>

            <nav className="order-3 flex w-full items-center justify-center gap-5 text-sm font-medium text-slate-600 md:order-none md:w-auto md:gap-6">
              <a href="#home" className="transition hover:text-cyan-700">Home</a>
              <a href="#about" className="transition hover:text-cyan-700">About</a>
              <a href="#how" className="transition hover:text-cyan-700">How it works</a>
              <a href="#requirements" className="transition hover:text-cyan-700">Requirements</a>
              <a href="#announcements" className="transition hover:text-cyan-700">Announcements</a>
              <a href="#contact" className="transition hover:text-cyan-700">Contact</a>
            </nav>

            <a
              href={`${legacySiteBase}/unified_login.php`}
              className="inline-flex items-center rounded-full bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-slate-800"
            >
              Sign In
            </a>
          </div>
        </div>
      </header>

      <main>
        <section id="home" className="relative overflow-hidden px-4 pb-10 pt-28 sm:px-6 lg:px-8 lg:pb-14 lg:pt-36">
          {/* Background image behind the hero card. Opacity and treatment match legacy subtle look. */}
          <div className="absolute inset-0 -z-10 overflow-hidden">
            <img
              src={heroBackground}
              alt="Hero background"
              className="h-full w-full object-cover opacity-30 grayscale-[0.02]"
            />
            <div className="absolute inset-0 bg-white/40" />
          </div>
          <div className="mx-auto max-w-7xl">
            <Card className="relative mx-auto max-w-[900px] overflow-hidden px-6 py-10 sm:px-10 sm:py-12">
              <div className="absolute inset-0 bg-[radial-gradient(circle_at_top,_rgba(255,255,255,0.8),_transparent_55%)]" />
              <div className="relative flex flex-col items-center text-center">
                <span className="mb-1 inline-flex items-center rounded-full bg-blue-50 px-4 py-2 text-sm font-semibold text-blue-700 ring-1 ring-blue-100">
                  General Trias Scholarship & Aid
                </span>
                <h1 className="max-w-3xl text-[2.8rem] font-semibold leading-[1.06] tracking-tight text-slate-950 sm:text-[3.5rem] lg:text-[3.8rem]">
                  Educational Assistance, Simplified.
                </h1>
                <p className="mt-4 max-w-3xl text-[1rem] leading-7 text-slate-600 sm:text-[1.03rem]">
                  Apply, upload requirements, track status, and claim assistance with QR — all in one city-run portal
                  designed for students and families in General Trias.
                </p>

                <div className="mt-6 flex flex-col gap-3 sm:flex-row">
                  <a
                    href={`${legacySiteBase}/register.php`}
                    className="relative z-40 inline-flex items-center justify-center rounded-full bg-cyan-600 px-6 py-3 text-[0.98rem] font-semibold text-white shadow-2xl transition hover:-translate-y-0.5 hover:bg-cyan-500"
                  >
                    Apply Now
                  </a>
                  <a
                    href={`${legacySiteBase}/unified_login.php`}
                    className="relative z-40 inline-flex items-center justify-center rounded-full border-2 border-cyan-600 bg-white/10 px-6 py-3 text-[0.98rem] font-semibold text-white shadow-sm backdrop-blur-sm transition hover:-translate-y-0.5 hover:bg-cyan-600 hover:text-white"
                    aria-label="Sign in to EducAid"
                  >
                    Sign In
                  </a>
                </div>
              </div>
            </Card>
          </div>
        </section>

        <section className="px-4 pt-8 sm:px-6 lg:px-8">
          <div className="mx-auto max-w-7xl">
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
              {quickLinks.map((item) => (
                <a
                  key={item.label}
                  href={item.href}
                  className="group rounded-[0.95rem] border border-slate-200 bg-white p-4 shadow-sm transition hover:-translate-y-1 hover:border-cyan-300 hover:shadow-md"
                >
                  <div className="flex items-center gap-3">
                    <BadgeIcon path={item.icon} />
                    <div className="min-w-0">
                      <div className="text-[0.92rem] font-semibold text-slate-950 transition group-hover:text-cyan-700">{item.label}</div>
                      <div className="mt-1 text-[0.72rem] text-slate-500">Open legacy page</div>
                    </div>
                  </div>
                </a>
              ))}
            </div>
          </div>
        </section>

        <section className="px-4 py-14 sm:px-6 lg:px-8">
          <div className="mx-auto max-w-7xl">
            <Card className="p-5 sm:p-6">
              <div className="grid gap-5 lg:grid-cols-[0.15fr_1fr] lg:items-center">
                <div className="flex items-center justify-center lg:justify-start">
                  <img
                    src="https://www.generaltrias.gov.ph/storage/image_upload/mayor.PNG"
                    alt="Mayor Jon-Jon Ferrer"
                    className="h-24 w-24 rounded-full object-cover ring-4 ring-cyan-50"
                  />
                </div>
                <div>
                  <SectionTitle
                    eyebrow="Mayor's Message"
                    title="Message from the Mayor"
                    lead="Welcome to the City Government of General Trias online platform, built to enhance connectivity, accessibility, and transparency for our thriving community."
                  />
                  <p className="mt-4 text-slate-600">
                    Through this portal, we aim to empower students and families with timely information and accessible
                    services, upholding transparency and accountability in governance.
                  </p>
                  <div className="mt-5 flex flex-wrap items-center gap-4">
                    <img
                      src="https://www.generaltrias.gov.ph/storage/image_upload/mayorpng.png"
                      alt="Mayor signature"
                      className="h-14 w-auto"
                    />
                    <div className="text-sm text-slate-600">
                      <strong className="text-slate-950">Hon. Luis &quot;Jon-Jon&quot; Ferrer IV</strong>
                      <br />
                      City Mayor, General Trias
                    </div>
                  </div>
                  <a
                    href="https://generaltrias.gov.ph/"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="mt-4 inline-flex text-sm font-semibold text-cyan-700 transition hover:text-cyan-600"
                  >
                    Read full message on the official website
                  </a>
                </div>
              </div>
            </Card>
          </div>
        </section>

        <section id="distribution-status" className="px-4 py-14 sm:px-6 lg:px-8">
          <div className="mx-auto max-w-7xl">
            <div className="text-center">
              <div className="mx-auto grid h-20 w-20 place-items-center rounded-full bg-cyan-600 text-white shadow-lg shadow-cyan-600/20">
                <svg viewBox="0 0 24 24" className="h-10 w-10 fill-current" aria-hidden="true">
                  <path d="M7 2.75a.75.75 0 0 1 .75.75V5h8.5V3.5a.75.75 0 0 1 1.5 0V5H19a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h1.25V3.5A.75.75 0 0 1 7 2.75Zm12.5 8.5h-15v8a.5.5 0 0 0 .5.5h14a.5.5 0 0 0 .5-.5v-8Z" />
                </svg>
              </div>
              <h2 className="mt-4 text-[1.45rem] font-semibold tracking-tight text-slate-950 sm:text-[1.8rem]">
                <span className="text-cyan-700">Application Status</span> & Slot Availability
              </h2>
              <p className="mx-auto mt-2 max-w-2xl text-[0.93rem] text-slate-600">Check current distribution status and available slots.</p>
            </div>

            <Card className="mt-7 p-5 sm:p-6 lg:p-8">
              <div className="rounded-[1.2rem] bg-cyan-50 p-4 text-center ring-1 ring-cyan-100 sm:p-5">
                <div className="inline-flex flex-wrap items-center justify-center gap-3 text-sm font-medium text-slate-700">
                  <span className="inline-flex items-center gap-2 rounded-full bg-white px-4 py-2 shadow-sm ring-1 ring-slate-200">
                    <span className="h-2.5 w-2.5 rounded-full bg-slate-400" />
                    Status: Loading
                  </span>
                  <span className="inline-flex items-center gap-2 rounded-full bg-white px-4 py-2 shadow-sm ring-1 ring-slate-200">
                    <span className="h-2.5 w-2.5 rounded-full bg-cyan-500" />
                    Period: —
                  </span>
                </div>
                <p className="mt-3 text-sm text-slate-600">No active distribution at the moment. Please check Announcements for updates.</p>
              </div>

              <div className="mt-6 grid gap-5 lg:grid-cols-2">
                <div className="rounded-[1.2rem] border-2 border-cyan-100 bg-cyan-50 p-5 text-center">
                  <div className="mx-auto grid h-16 w-16 place-items-center rounded-full bg-cyan-600 text-3xl font-bold text-white shadow-lg shadow-cyan-600/25">
                    —
                  </div>
                  <div className="mt-4 text-[3.2rem] font-semibold leading-none tracking-tight text-cyan-700">—</div>
                  <div className="mt-1 text-lg font-medium text-slate-700">Slots Available</div>
                  <div className="mt-1 text-sm text-slate-500">out of — total slots</div>
                </div>

                <div className="rounded-[1.2rem] border-2 border-emerald-100 bg-emerald-50 p-5">
                  <div className="flex items-center justify-between text-sm font-semibold text-slate-700">
                    <span>Distribution Progress</span>
                    <span className="rounded-full bg-white px-3 py-1 text-cyan-700 ring-1 ring-cyan-100">—%</span>
                  </div>
                  <div className="mt-4 h-4 overflow-hidden rounded-full bg-slate-200">
                    <div className="h-full w-0 rounded-full bg-gradient-to-r from-cyan-500 to-emerald-500" />
                  </div>
                  <div className="mt-2 flex justify-between text-xs text-slate-500">
                    <span>Empty</span>
                    <span>Full</span>
                  </div>

                  <div className="mt-6 space-y-3">
                    <a
                      href={`${legacySiteBase}/register.php`}
                      className="flex w-full items-center justify-center rounded-full bg-cyan-600 px-6 py-3 font-semibold text-white shadow-lg shadow-cyan-600/20 transition hover:-translate-y-0.5 hover:bg-cyan-500"
                    >
                      Apply Now
                    </a>
                    <a
                      href={`${legacySiteBase}/unified_login.php`}
                      className="flex w-full items-center justify-center rounded-full border border-cyan-600 px-6 py-3 font-semibold text-cyan-700 transition hover:bg-cyan-600 hover:text-white"
                    >
                      Track My Application
                    </a>
                  </div>
                </div>
              </div>

              <div className="mt-7 grid gap-4 border-t border-dashed border-cyan-100 pt-5 sm:grid-cols-3">
                {[
                  ['Real-time Updates', '⚡'],
                  ['Secure Portal', '🛡️'],
                  ['24/7 Access', '⏰'],
                ].map(([label, icon]) => (
                  <div key={label} className="flex items-center justify-center gap-2 text-sm font-medium text-slate-600">
                    <span className="text-lg">{icon}</span>
                    <span>{label}</span>
                  </div>
                ))}
              </div>
            </Card>
          </div>
        </section>

        <section id="about" className="px-4 py-14 sm:px-6 lg:px-8">
          <div className="mx-auto grid max-w-7xl gap-8 lg:grid-cols-2 lg:items-start">
            <div>
              <SectionTitle
                eyebrow="About"
                title="What is EducAid?"
                lead="EducAid is the City of General Trias official Educational Assistance Management System. Built with transparency and accessibility in mind, it streamlines application, evaluation, release, and reporting of aid for qualified students."
              />
              <div className="mt-7 grid gap-4 sm:grid-cols-2">
                {[
                  ['Secure & Private', 'Data protected under RA 10173 and city policies.', '🔒'],
                  ['QR-based Claiming', 'Fast verification on distribution day via secure QR codes.', '🧾'],
                  ['Real-time Updates', 'Get notified on slots, schedules, and requirements.', '🔔'],
                  ['LGU-Managed', 'Powered by the Office of the Mayor and partner departments.', '🏛️'],
                ].map(([title, body, icon]) => (
                  <Card key={title} className="p-4">
                    <div className="flex items-start gap-3">
                      <span className="mt-1 text-2xl">{icon}</span>
                      <div>
                        <div className="font-semibold text-slate-950">{title}</div>
                        <p className="mt-1.5 text-sm leading-6 text-slate-600">{body}</p>
                      </div>
                    </div>
                  </Card>
                ))}
              </div>
            </div>

            <Card className="p-5 sm:p-6">
              <h3 className="text-xl font-semibold text-slate-950">What you can do</h3>
              <div className="mt-4 grid gap-4 sm:grid-cols-2">
                {[
                  ['Apply online', 'Create an account and submit your application securely.'],
                  ['Get updates', 'Receive notifications on status, schedules, and reminders.'],
                  ['Track application', 'Sign in anytime to view progress and required actions.'],
                  ['QR-based claiming', 'Fast, secure verification on distribution day.'],
                ].map(([title, body]) => (
                  <div key={title} className="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                    <div className="font-semibold text-slate-950">{title}</div>
                    <p className="mt-2 text-sm leading-6 text-slate-600">{body}</p>
                  </div>
                ))}
              </div>
              <div className="mt-6 flex flex-wrap gap-3">
                <a
                  href={`${legacySiteBase}/register.php`}
                  className="inline-flex items-center rounded-full bg-cyan-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-cyan-500"
                >
                  Apply Now
                </a>
                <a
                  href={`${legacySiteBase}/unified_login.php`}
                  className="inline-flex items-center rounded-full border border-cyan-600 px-5 py-2.5 text-sm font-semibold text-cyan-700 transition hover:bg-cyan-600 hover:text-white"
                >
                  Track Application
                </a>
              </div>
            </Card>
          </div>
        </section>

        <section id="how" className="px-4 py-14 sm:px-6 lg:px-8">
          <div className="mx-auto max-w-7xl">
            <SectionTitle
              eyebrow="How it works"
              title="The four-step process"
              lead="A simple application flow that keeps the user experience close to the legacy site while making the layout Tailwind-native."
              centered
            />
            <div className="mt-8 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
              {howSteps.map((item) => (
                <Card key={item.step} className="p-4">
                  <div className="flex items-center gap-3">
                    <div className="grid h-12 w-12 place-items-center rounded-2xl bg-cyan-600 text-lg font-bold text-white">
                      {item.step}
                    </div>
                    <h3 className="text-lg font-semibold text-slate-950">{item.title}</h3>
                  </div>
                  <p className="mt-3 text-sm leading-6 text-slate-600">{item.description}</p>
                </Card>
              ))}
            </div>
          </div>
        </section>

        <section id="announcements" className="bg-white/70 px-4 py-14 sm:px-6 lg:px-8">
          <div className="mx-auto max-w-7xl">
            <div className="mb-5 flex items-end justify-between gap-3">
              <div>
                <SectionTitle
                  eyebrow="Announcements"
                  title="Latest Announcements"
                  lead="Recent official updates and schedules from the municipality."
                />
              </div>
              <a
                href={`${legacySiteBase}/website/announcements.php`}
                className="hidden rounded-full border border-cyan-600 px-4 py-2 text-sm font-semibold text-cyan-700 transition hover:bg-cyan-600 hover:text-white md:inline-flex"
              >
                View All
              </a>
            </div>
            <div className="grid gap-4 md:grid-cols-3">
              {announcements.map((item) => (
                <Card key={item.title} className="overflow-hidden p-0">
                  <div className="relative aspect-[16/9] overflow-hidden bg-slate-100">
                    <img src={item.image} alt="Announcement thumbnail" className="h-full w-full object-cover" />
                    <div className="absolute inset-0 bg-[linear-gradient(180deg,rgba(15,23,42,0.02),rgba(15,23,42,0.24))]" />
                    {item.active ? (
                      <span className="absolute left-3 top-3 rounded-full bg-emerald-500 px-2.5 py-1 text-[0.62rem] font-semibold uppercase tracking-[0.22em] text-white shadow-sm shadow-emerald-500/25">
                        Active
                      </span>
                    ) : null}
                  </div>
                  <div className="p-4">
                    <div className="flex items-center justify-between gap-3 text-xs font-semibold uppercase tracking-[0.22em] text-cyan-700">
                      <span>{item.date}</span>
                    </div>
                    <h3 className="mt-3 text-lg font-semibold text-slate-950">{item.title}</h3>
                    <p className="mt-2 text-sm leading-6 text-slate-600">{item.body}</p>
                    <div className="mt-4 text-sm font-semibold text-cyan-700">View details →</div>
                  </div>
                </Card>
              ))}
            </div>
          </div>
        </section>

        <section id="requirements" className="px-4 py-14 sm:px-6 lg:px-8">
          <div className="mx-auto max-w-7xl">
            <SectionTitle
              eyebrow="Requirements"
              title="Basic Requirements"
              lead="Prepare clear photos or PDFs of the following. Additional documents may be requested for verification."
            />
            <div className="mt-8 grid gap-4 lg:grid-cols-2">
              <Card className="p-5">
                <h3 className="text-lg font-semibold text-slate-950">Identity & Enrollment</h3>
                <ul className="mt-4 grid gap-3 text-sm text-slate-700">
                  {requirements.slice(0, 4).map((item) => (
                    <li key={item} className="flex items-start gap-3 rounded-2xl bg-slate-50 p-3 ring-1 ring-slate-200">
                      <span className="mt-0.5 text-cyan-700">✓</span>
                      <span>{item}</span>
                    </li>
                  ))}
                </ul>
              </Card>
              <Card className="p-5">
                <h3 className="text-lg font-semibold text-slate-950">Account & Contact</h3>
                <ul className="mt-4 grid gap-3 text-sm text-slate-700">
                  {requirements.slice(4).map((item) => (
                    <li key={item} className="flex items-start gap-3 rounded-2xl bg-slate-50 p-3 ring-1 ring-slate-200">
                      <span className="mt-0.5 text-cyan-700">✓</span>
                      <span>{item}</span>
                    </li>
                  ))}
                </ul>
              </Card>
            </div>
          </div>
        </section>

        <section id="faq" className="bg-white/70 px-4 py-14 sm:px-6 lg:px-8">
          <div className="mx-auto max-w-7xl">
            <SectionTitle
              eyebrow="FAQ"
              title="Frequently Asked Questions"
              lead="Quick answers to common concerns about eligibility, slots, and claiming."
              centered
            />
            <div className="mx-auto mt-8 max-w-4xl space-y-4">
              {faqItems.map((item, index) => (
                <Card key={item.q} className="p-5">
                  <div className="flex items-start gap-4">
                    <div className="grid h-10 w-10 place-items-center rounded-2xl bg-cyan-50 font-semibold text-cyan-700 ring-1 ring-cyan-100">
                      {index + 1}
                    </div>
                    <div>
                      <h3 className="text-lg font-semibold text-slate-950">{item.q}</h3>
                      <p className="mt-2 text-sm leading-7 text-slate-600">{item.a}</p>
                    </div>
                  </div>
                </Card>
              ))}
            </div>
          </div>
        </section>

        <section id="contact" className="px-4 py-14 sm:px-6 lg:px-8">
          <div className="mx-auto grid max-w-7xl gap-6 lg:grid-cols-2">
            <Card className="p-5 sm:p-6">
              <SectionTitle
                eyebrow="Contact"
                title="Contact & Helpdesk"
                lead="For inquiries about requirements, schedules, or account issues, reach us here."
              />
              <ul className="mt-6 grid gap-3 text-sm text-slate-700">
                <li className="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">educaid@generaltrias.gov.ph</li>
                <li className="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">(046) 886-4454</li>
                <li className="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">City Government of General Trias, Cavite</li>
              </ul>
              <div className="mt-6 flex flex-wrap gap-3">
                <a
                  href={`${legacySiteBase}/register.php`}
                  className="inline-flex items-center rounded-full bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-500"
                >
                  Start Application
                </a>
                <a
                  href={`${legacySiteBase}/website/announcements.php`}
                  className="inline-flex items-center rounded-full border border-cyan-600 px-5 py-2.5 text-sm font-semibold text-cyan-700 transition hover:bg-cyan-600 hover:text-white"
                >
                  See Announcements
                </a>
                <a
                  href={`${legacySiteBase}/website/contact.php`}
                  className="inline-flex items-center rounded-full border border-slate-300 px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-400 hover:text-slate-950"
                >
                  Full Contact Page
                </a>
              </div>
            </Card>

            <Card className="overflow-hidden p-0">
              <iframe
                title="General Trias City Hall Location"
                src="https://www.google.com/maps?q=9VPJ+F9Q,+General+Trias,+Cavite&output=embed&z=17"
                className="h-full min-h-[320px] w-full border-0"
                loading="lazy"
                referrerPolicy="no-referrer-when-downgrade"
              />
            </Card>
          </div>
        </section>
      </main>

      <footer className="border-t border-slate-200 bg-[#0051f8] px-4 py-12 text-white sm:px-6 lg:px-8">
        <div className="mx-auto max-w-7xl">
          <div className="grid gap-8 lg:grid-cols-[1.2fr_1fr] lg:items-start">
            <div className="flex items-center gap-4">
              <div className="grid h-16 w-16 place-items-center rounded-2xl bg-[#fbbf24] text-lg font-bold text-[#0051f8] shadow-lg shadow-black/10">
                EA
              </div>
              <div>
                <div className="text-lg font-semibold">EducAid • General Trias</div>
                <p className="mt-1 max-w-md text-sm text-white/90">Let's join forces for a more progressive GenTrias.</p>
              </div>
            </div>

            <div className="grid gap-6 sm:grid-cols-3">
              <div>
                <h3 className="text-sm font-semibold uppercase tracking-[0.2em] text-white">Explore</h3>
                <ul className="mt-3 space-y-2 text-sm text-white/90">
                  <li><a href="#home" className="transition hover:text-[#fbbf24]">Home</a></li>
                  <li><a href="#about" className="transition hover:text-[#fbbf24]">About</a></li>
                  <li><a href="#how" className="transition hover:text-[#fbbf24]">Process</a></li>
                  <li><a href="#announcements" className="transition hover:text-[#fbbf24]">Announcements</a></li>
                </ul>
              </div>

              <div>
                <h3 className="text-sm font-semibold uppercase tracking-[0.2em] text-white">Resources</h3>
                <ul className="mt-3 space-y-2 text-sm text-white/90">
                  <li><a href="#requirements" className="transition hover:text-[#fbbf24]">Requirements</a></li>
                  <li><a href="#faq" className="transition hover:text-[#fbbf24]">FAQs</a></li>
                  <li><a href="#contact" className="transition hover:text-[#fbbf24]">Contact</a></li>
                </ul>
              </div>

              <div>
                <h3 className="text-sm font-semibold uppercase tracking-[0.2em] text-white">Contact Info</h3>
                <ul className="mt-3 space-y-2 text-sm text-white/90">
                  <li>General Trias City Hall, Cavite</li>
                  <li>(046) 886-4454</li>
                  <li>educaid@generaltrias.gov.ph</li>
                </ul>
              </div>
            </div>
          </div>

          <hr className="my-8 border-white/20" />

          <div className="flex flex-col gap-2 text-center text-sm text-white/85 sm:flex-row sm:items-center sm:justify-between sm:text-left">
            <span>© {new Date().getFullYear()} City Government of General Trias • EducAid</span>
            <span>Developed by CTRL+Solutions</span>
          </div>
        </div>
      </footer>
    </div>
  );
}