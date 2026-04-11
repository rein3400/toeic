// Three.js Background Animation
const canvas = document.getElementById('bg-canvas');
const renderer = new THREE.WebGLRenderer({ canvas, alpha: true, antialias: true });
renderer.setSize(window.innerWidth, window.innerHeight);
renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));

const scene = new THREE.Scene();
const camera = new THREE.PerspectiveCamera(75, window.innerWidth / window.innerHeight, 0.1, 1000);
camera.position.z = 30;

// Create floating particles
const particlesGeometry = new THREE.BufferGeometry();
const particlesCount = 1500;
const posArray = new Float32Array(particlesCount * 3);

for (let i = 0; i < particlesCount * 3; i++) {
    posArray[i] = (Math.random() - 0.5) * 100;
}

particlesGeometry.setAttribute('position', new THREE.BufferAttribute(posArray, 3));

const particlesMaterial = new THREE.PointsMaterial({
    size: 0.1,
    color: 0x6366f1,
    transparent: true,
    opacity: 0.8,
    blending: THREE.AdditiveBlending
});

const particlesMesh = new THREE.Points(particlesGeometry, particlesMaterial);
scene.add(particlesMesh);

// Create floating torus shapes
const torusGeometry = new THREE.TorusGeometry(8, 2, 16, 100);
const torusMaterial = new THREE.MeshBasicMaterial({
    color: 0x2563eb,
    wireframe: true,
    transparent: true,
    opacity: 0.1
});
const torus = new THREE.Mesh(torusGeometry, torusMaterial);
torus.position.set(-20, 10, -20);
scene.add(torus);

const torus2 = new THREE.Mesh(
    new THREE.TorusGeometry(6, 1.5, 16, 100),
    new THREE.MeshBasicMaterial({ color: 0x059669, wireframe: true, transparent: true, opacity: 0.1 })
);
torus2.position.set(25, -15, -25);
scene.add(torus2);

// Mouse interaction
let mouseX = 0, mouseY = 0;
document.addEventListener('mousemove', (e) => {
    mouseX = (e.clientX / window.innerWidth - 0.5) * 2;
    mouseY = (e.clientY / window.innerHeight - 0.5) * 2;
});

// Animation loop
function animate() {
    requestAnimationFrame(animate);

    particlesMesh.rotation.x += 0.0003;
    particlesMesh.rotation.y += 0.0005;

    torus.rotation.x += 0.002;
    torus.rotation.y += 0.003;

    torus2.rotation.x -= 0.002;
    torus2.rotation.y -= 0.001;

    camera.position.x += (mouseX * 3 - camera.position.x) * 0.02;
    camera.position.y += (-mouseY * 3 - camera.position.y) * 0.02;
    camera.lookAt(scene.position);

    renderer.render(scene, camera);
}
animate();

// Resize handler
window.addEventListener('resize', () => {
    camera.aspect = window.innerWidth / window.innerHeight;
    camera.updateProjectionMatrix();
    renderer.setSize(window.innerWidth, window.innerHeight);
});

// Navbar scroll effect
window.addEventListener('scroll', () => {
    const navbar = document.querySelector('.navbar');
    if (window.scrollY > 50) {
        navbar.classList.add('scrolled');
    } else {
        navbar.classList.remove('scrolled');
    }
});

// Animate counters
const animateCounter = (el, target) => {
    let current = 0;
    const increment = target / 60;
    const timer = setInterval(() => {
        current += increment;
        if (current >= target) {
            el.textContent = target.toLocaleString();
            clearInterval(timer);
        } else {
            el.textContent = Math.floor(current).toLocaleString();
        }
    }, 16);
};

// Intersection Observer for counters
const statsObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            const numbers = document.querySelectorAll('.stat-number');
            numbers.forEach(num => {
                const target = parseInt(num.dataset.target);
                animateCounter(num, target);
            });
            statsObserver.disconnect();
        }
    });
});

statsObserver.observe(document.querySelector('.hero-stats'));

// Smooth scroll
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
});
