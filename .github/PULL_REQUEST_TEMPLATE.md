## 📋 Pull Request Summary

### Sprint Reference
- **Sprint**: [SPRINT-YYYYMMDD-description](../docs/sprints/SPRINT-YYYYMMDD-description.md)
- **Related Issues**: Closes #XXX, Closes #YYY
- **Branch**: `claudeflow-YYMMDD-HHmm`

### Type of Change
- [ ] 🐛 Bug fix (non-breaking change which fixes an issue)
- [ ] ✨ New feature (non-breaking change which adds functionality)
- [ ] 💥 Breaking change (fix or feature that would cause existing functionality to not work as expected)
- [ ] 📚 Documentation update
- [ ] 🔧 Configuration/Infrastructure change
- [ ] ♻️ Code refactoring
- [ ] ✅ Test improvements
- [ ] 🚀 Performance improvement
- [ ] 🔒 Security improvement

---

## 📝 Description

### What does this PR do?
[Clear description of changes]

### Why is this change needed?
[Motivation and context]

### How has this been implemented?
[Technical implementation details]

---

## ✅ Checklist

### Code Quality
- [ ] My code follows the project's style guidelines
- [ ] I have performed a self-review of my code
- [ ] I have commented my code, particularly in hard-to-understand areas
- [ ] My changes generate no new warnings or errors
- [ ] Code files are under 500 lines (per CLAUDE.md standard)

### Testing
- [ ] I have added tests that prove my fix is effective or that my feature works
- [ ] New and existing unit tests pass locally with my changes
- [ ] I have tested this with relevant Docker topologies (if applicable)
- [ ] Test coverage has not decreased

### Documentation
- [ ] I have updated the README.md if needed
- [ ] I have updated relevant documentation in docs/
- [ ] I have updated the sprint documentation
- [ ] I have added PHPDoc comments for new public functions/methods

### Git Workflow (CRITICAL per CLAUDE.md)
- [ ] I have created this PR from a feature branch (NOT main)
- [ ] I have rebased on latest main
- [ ] My commits are logical and well-described
- [ ] I have followed the branch naming convention (`claudeflow-YYMMDD-HHmm`)
- [ ] I understand this PR requires manual approval on github.com

---

## 🧪 Testing Performed

### Test Commands Run
```bash
# Example:
./run_tests.sh
docker-compose -f docker-compose-4line.yml up -d
# ... test commands ...
docker-compose -f docker-compose-4line.yml down
```

### Test Results
```
[Paste relevant test output or describe manual testing performed]
```

### Test Coverage
| Component | Before | After | Δ |
|-----------|--------|-------|---|
| Overall | XX% | YY% | +ZZ% |
| New Code | N/A | ZZ% | - |

---

## 📊 Performance Impact

[If applicable, describe performance changes]

- **Build time**: [before] → [after] (±X%)
- **Test execution**: [before] → [after] (±X%)
- **Docker image size**: [before] → [after] (±X%)
- **Runtime performance**: [metrics if applicable]

---

## 💥 Breaking Changes

- [ ] No breaking changes
- [ ] Breaking changes (describe below and provide migration guide)

### Breaking Changes Description
[If this PR includes breaking changes, describe them here]

### Migration Guide
[Provide step-by-step migration instructions for users]

---

## 📸 Screenshots/Examples

[If applicable, add screenshots, command output examples, or diagrams]

**Before:**
```
[Show before state]
```

**After:**
```
[Show after state]
```

---

## 🔗 Additional Context

### Dependencies
- [ ] No new dependencies
- [ ] New dependencies added (list below with justification):

**New Dependencies:**
```json
{
  "package-name": "reason for adding"
}
```

### Configuration Changes
- [ ] No configuration changes
- [ ] Configuration changes required (describe below):

[List any configuration file changes, environment variables, Docker changes, etc.]

### Database Changes
- [ ] No database schema changes
- [ ] Database schema changes (describe below):

[Describe table changes, migrations needed, etc.]

### Deployment Notes
[Any special considerations for deployment]

- [ ] Requires database migration
- [ ] Requires configuration update
- [ ] Requires Docker rebuild
- [ ] Requires service restart
- [ ] No special deployment steps needed

### Follow-up Work
[Issues or PRs that should follow this change]

- [ ] Create follow-up issue for #XXX
- [ ] Plan future enhancement #YYY

---

## 👀 Reviewer Notes

### Areas of Focus
[Specific areas you want reviewers to pay attention to]

1. Please review [specific file/function] carefully
2. I'm particularly unsure about [specific approach]
3. Alternative approaches considered: [list]

### Questions for Reviewers
[Any specific questions or concerns you have]

1. Question 1?
2. Question 2?

### Testing Checklist for Reviewers
- [ ] Code review completed
- [ ] Tests run successfully
- [ ] Docker topologies tested (if applicable)
- [ ] Documentation reviewed
- [ ] Security implications considered

---

## 🔐 Security Considerations

[If this PR has security implications]

- [ ] No security implications
- [ ] Security review completed
- [ ] Security implications (describe below):

[Describe security considerations, threat model changes, etc.]

---

## 📚 Related Documentation

- [Link to sprint document](../docs/sprints/SPRINT-YYYYMMDD-description.md)
- [Link to architecture doc](../docs/architecture/COMPONENT.md)
- [Link to related issue](#XXX)

---

**Ready for Review**: [ ] Yes / [ ] No (Draft)

---

## 📝 Reviewer Comments

<!-- Reviewers: Please leave comments below -->

<!-- After approval, remember per CLAUDE.md:
1. Manual approval required on github.com
2. After merge, delete both local and remote branch
3. Update sprint documentation
4. Close related issues
-->
