const fs = require('fs');
const path = require('path');
const sass = require('sass');
const postcss = require('postcss');
const autoprefixer = require('autoprefixer');
const CleanCSS = require('clean-css');
const Terser = require('terser');
const esbuild = require('esbuild');

const PLUGIN_NAME = 'linkcard';

// Define paths
const srcDir = path.join(__dirname, '..', 'src');
const distDir = path.join(__dirname, '..', 'dist');

// Ensure dist directory exists
if (!fs.existsSync(distDir)) {
    fs.mkdirSync(distDir, { recursive: true });
}

// Check if required files exist
const requiredFiles = [
    path.join(srcDir, 'css', `${PLUGIN_NAME}.scss`),
    path.join(srcDir, 'ts', `${PLUGIN_NAME}.ts`),
    path.join(srcDir, `${PLUGIN_NAME}.php`)
];
for (const file of requiredFiles) {
    if (!fs.existsSync(file)) {
        console.error(`Required file not found: ${file}`);
        process.exit(1);
    }
}

// Compile and minify SCSS to CSS
async function compileSCSS(fileName) {
    const scssFile = path.join(srcDir, 'css', fileName);

    // 1. SCSS → CSS
    const result = sass.compile(scssFile, {
        style: 'expanded',
        sourceMap: false,
        quietDeps: true
    });

    let css = result.css;

    // 2. autoprefix
    const postcssResult = await postcss([
        autoprefixer({
            overrideBrowserslist: [
                '>= 1%',
                'last 2 versions',
                'not dead'
            ]
        })
    ]).process(css, { from: undefined });

    css = postcssResult.css;

    // 3. minify
    return new CleanCSS({
        level: {
            1: { specialComments: 0 },
            2: { restructureRules: false }
        }
    }).minify(css).styles;
}

// Minify JavaScript
async function minifyJS(jsContent) {
    try {
        const result = await Terser.minify(jsContent, {
            ecma: 2022,
            module: true,
            sourceMap: false,
            compress: {
                drop_console: true,
                drop_debugger: true,
                passes: 2,
            },
            mangle: true,
            format: {
                comments: false
            }
        });

        if (!result.code) {
            throw new Error('Terser returned no code');
        }

        return result.code;
    } catch (error) {
        console.error('Error minifying JS:', error);
        throw error;
    }
}

// Main build function
async function build() {
    try {
        console.log('Starting build process...');

        // Step 1: Compile and minify SCSS
        console.log('Compiling SCSS...');
        const minifiedLinkcardCSS = await compileSCSS(`${PLUGIN_NAME}.scss`);
        const minifiedManageCSS = await compileSCSS(`${PLUGIN_NAME}-manage.scss`);
        console.log('SCSS compiled and minified successfully');

        // Step 2: Read JS source and inject CSS
        console.log('Processing JS...');
        let jsContent = fs.readFileSync(path.join(srcDir, 'ts', `${PLUGIN_NAME}.ts`), 'utf8');

        // Inject linkcard CSS into JS
        jsContent = jsContent.replace(/\{css\}/g, minifiedLinkcardCSS.replace(/\\/g, '\\\\').replace(/'/g, "\\'"));

        // Step 3: Strip TypeScript types using esbuild
        console.log('Stripping TypeScript types...');
        const esbuildResult = esbuild.transformSync(jsContent, {
            loader: 'ts',
            target: 'es2022',
            minify: false,
        });
        jsContent = esbuildResult.code;

        // Step 4: Minify JS
        console.log('Minifying JS...');
        const minifiedJS = await minifyJS(jsContent);

        // Step 5: Read PHP and inject JS/CSS
        console.log('Building PHP...');
        const phpContent = fs.readFileSync(path.join(srcDir, `${PLUGIN_NAME}.php`), 'utf8');
        let modifiedPhpContent = phpContent.replace(/\{js\}/g, minifiedJS.replace(/\\/g, '\\\\').replace(/'/g, "\\'"));
        modifiedPhpContent = modifiedPhpContent.replace(/\{css-manage\}/g, minifiedManageCSS.replace(/\\/g, '\\\\').replace(/'/g, "\\'"));

        fs.writeFileSync(path.join(distDir, `${PLUGIN_NAME}.inc.php`), modifiedPhpContent);
        console.log(`${PLUGIN_NAME}.inc.php updated in dist directory`);

        // Verify no placeholders remain
        const output = fs.readFileSync(path.join(distDir, `${PLUGIN_NAME}.inc.php`), 'utf8');
        const remaining = ['{js}', '{css}', '{css-manage}'].filter(p => output.includes(p));
        if (remaining.length > 0) {
            console.error(`WARNING: Placeholders still remain: ${remaining.join(', ')}`);
        } else {
            console.log('All placeholders replaced successfully');
        }

        console.log('Build completed successfully!');
        console.log('Files created in dist directory:');
        console.log(`- ${PLUGIN_NAME}.inc.php`);

    } catch (error) {
        console.error('Build failed:', error);
        process.exit(1);
    }
}

// Run build
build();
