import { createRequire } from 'node:module'
import chalk from 'chalk'
import figlet from 'figlet'
import concurrently from 'concurrently'

const require = createRequire(import.meta.url)
const clear = require('clear')

clear()

const banner = figlet.textSync('LEEZR', { font: 'ANSI Shadow' })

console.log(chalk.cyan(banner))
console.log()
console.log(chalk.magenta.bold('  Leezr Dev Environment'))
console.log()
console.log(`  ${chalk.white('App')}      ${chalk.dim('→')} ${chalk.cyan('https://leezr.test')}`)
console.log(`  ${chalk.white('Vite')}     ${chalk.dim('→')} ${chalk.cyan('https://vite.leezr.test:5173')}`)
console.log(`  ${chalk.white('Mailpit')}  ${chalk.dim('→')} ${chalk.yellow('http://localhost:8025')}`)
console.log(`  ${chalk.white('Ollama')}   ${chalk.dim('→')} ${chalk.green('http://localhost:11434')}`)
console.log(`  ${chalk.white('Queue')}    ${chalk.dim('→')} ${chalk.blue('ai worker')}`)
console.log()
console.log(chalk.dim('  ─────────────────────────────────────'))
console.log()
console.log(chalk.white.bold('  Available commands:'))
console.log(`  ${chalk.green('pnpm dev:all')}     ${chalk.dim('→')} Standard dev (Vite only)`)
console.log(`  ${chalk.green('pnpm dev:leezr')}   ${chalk.dim('→')} Dev + Mailpit + Ollama + Queue`)
console.log(`  ${chalk.green('pnpm build')}       ${chalk.dim('→')} Production build`)
console.log(`  ${chalk.green('pnpm mailpit')}     ${chalk.dim('→')} Open Mailpit UI`)
console.log()
console.log(chalk.dim('  ─────────────────────────────────────'))
console.log()

const { result } = concurrently(
  [
    { command: 'mailpit', name: 'mailpit', prefixColor: 'gray' },
    { command: 'pnpm dev:all', name: 'vite', prefixColor: 'cyan' },
    { command: 'ollama serve', name: 'ollama', prefixColor: 'green' },
    { command: 'php artisan queue:work --queue=ai --sleep=3 --tries=3 --timeout=120', name: 'queue', prefixColor: 'blue' },
  ],
  {
    prefix: 'name',
    killOthers: 'never',
    restartTries: 0,
  },
)

result.catch(() => process.exit(1))
